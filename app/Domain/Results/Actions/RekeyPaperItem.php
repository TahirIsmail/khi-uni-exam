<?php

namespace App\Domain\Results\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\Marking\Queries\FinalMark;
use App\Domain\Marking\Support\ObjectiveItemScorer;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Enums\RekeyDecision;
use App\Domain\Results\Models\ItemRekey;
use App\Domain\Results\Models\ResultPublication;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * Correcting one paper item after the exam (exam phase, step 21) — a faulty item found once
 * marking is under way. Corrects the paper's own item, not the reusable question-bank record,
 * because the record was reviewed and approved on its own merits and stays exactly as it was.
 * Every candidate who sat this item is rescored, and if the examination's results were already
 * approved or published, that is reset to draft: a correction has to be looked at again before
 * anyone re-publishes it.
 */
final class RekeyPaperItem
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly ObjectiveItemScorer $scorer,
        private readonly CompileResult $compile,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, PaperItem $paperItem, RekeyDecision $decision, ?int $correctedOptionId, string $reason): ItemRekey
    {
        $examination = $paperItem->paper->examination;
        $this->guard->authorise($user, $examination, 'result.rescore', 'You cannot re-key items for this course.');

        $type = QuestionType::query()->findOrFail($paperItem->question_type_id);
        if ($type->is_manually_marked) {
            throw ValidationException::withMessages(['decision' => 'An essay has no key to re-key — mark it again instead.']);
        }
        if ($decision === RekeyDecision::CorrectOption && $type->has_items) {
            throw ValidationException::withMessages(['decision' => 'This question has sub-parts: it can only be discarded, not corrected in place.']);
        }
        if ($decision === RekeyDecision::CorrectOption && $correctedOptionId === null) {
            throw ValidationException::withMessages(['corrected_option_id' => 'Say which option is actually correct.']);
        }

        if (ItemRekey::query()->where('paper_item_id', $paperItem->id)->exists()) {
            throw ValidationException::withMessages(['item' => 'This item has already been re-keyed once; a further correction needs a fresh decision on what is now wrong.']);
        }

        return DB::transaction(function () use ($user, $paperItem, $decision, $correctedOptionId, $reason, $examination, $type): ItemRekey {
            $rekey = ItemRekey::query()->create([
                'paper_item_id' => $paperItem->id,
                'decision' => $decision,
                'corrected_option_id' => $correctedOptionId,
                'reason' => $reason,
                'decided_by' => $user->id,
                'decided_at' => now(),
            ]);

            $this->rescoreEveryAttempt($paperItem, $decision, $correctedOptionId, $type, $user, $examination);

            $publication = ResultPublication::query()->where('examination_id', $examination->id)->first();
            if ($publication !== null && $publication->status !== PublicationStatus::Draft) {
                $publication->update(['status' => PublicationStatus::Draft]);
            }

            return $rekey;
        });
    }

    private function rescoreEveryAttempt(PaperItem $paperItem, RekeyDecision $decision, ?int $correctedOptionId, QuestionType $type, User $user, Examination $examination): void
    {
        $version = QuestionVersion::query()->with(['options', 'items.answers', 'answers'])->findOrFail($paperItem->version_id);
        $candidateItems = CandidatePaperItem::query()->where('paper_item_id', $paperItem->id)->with('candidateExam')->get();

        $answers = DB::table('dlv_answers_current')->whereIn('cand_paper_item_id', $candidateItems->pluck('id'))
            ->get(['cand_paper_item_id', 'payload'])->keyBy('cand_paper_item_id');

        foreach ($candidateItems as $item) {
            $previousMarks = ItemMark::query()->where('candidate_exam_id', $item->candidate_exam_id)
                ->where('cand_paper_item_id', $item->id)->get();
            $previous = FinalMark::of($previousMarks, $examination->require_double_marking);

            $marks = $decision === RekeyDecision::Discard
                ? (float) $paperItem->marks
                : $this->scoreWithOverride($type, $version, $answers, $item, (float) $paperItem->marks, $correctedOptionId);

            $created = ItemMark::query()->create([
                'candidate_exam_id' => $item->candidate_exam_id,
                'cand_paper_item_id' => $item->id,
                'source' => MarkSource::Rekeyed,
                'marks_awarded' => round($marks, 2),
                'max_marks' => $paperItem->marks,
                'marked_by' => $user->id,
                'marked_at' => now(),
            ]);

            $this->audit->record(
                'result.rekeyed',
                'cand_paper_item',
                $item->id,
                $previous === null ? null : ['source' => $previous->source->value, 'marks_awarded' => $previous->marks_awarded],
                ['source' => MarkSource::Rekeyed->value, 'marks_awarded' => $created->marks_awarded],
                null,
                $user,
                $examination->branch_id,
            );

            $this->compile->__invoke($item->candidateExam);
        }
    }

    /**
     * @param  Collection<int, stdClass>  $answers
     */
    private function scoreWithOverride(QuestionType $type, QuestionVersion $version, Collection $answers, CandidatePaperItem $item, float $maxMarks, ?int $correctedOptionId): float
    {
        $row = $answers->get($item->id);
        $payload = $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);

        return $this->scorer->score($type, $version, $payload, $maxMarks, $correctedOptionId);
    }
}
