<?php

namespace App\Domain\Marking\Actions;

use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Enums\ExaminerRole;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ExaminerAssignment;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * An assigned examiner's mark for one item (exam phase, step 20): a flat mark for a non-rubric
 * manually-marked type, or a set of per-criterion marks for an essay, which must add up to the
 * mark given. Marking is blind — this never reads or exposes the other examiner's mark; only
 * FinaliseItemMark, once both are in, compares them.
 *
 * The same path serves all three things an examiner does: marking what no machine can mark,
 * confirming what a machine only suggested, and overturning what a machine decided. The last of
 * those must carry a reason. Nothing is edited either way — mrk_item_marks is append-only, so an
 * examiner's mark sits beside the auto mark for good and FinalMark decides which one counts.
 */
final class RecordExaminerMark
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly FinaliseItemMark $finalise,
    ) {}

    /**
     * @param  list<array{rubric_criterion_id: int, marks_awarded: float}>  $criteria
     */
    public function __invoke(User $examiner, CandidatePaperItem $item, float $marksAwarded, array $criteria, ?string $comments): ItemMark
    {
        $attempt = $item->candidateExam;
        $examination = $attempt->examination;

        $this->guard->authorise($examiner, $examination, 'marking.mark', 'You cannot mark this examination.');

        $assignment = ExaminerAssignment::query()->where('examination_id', $examination->id)
            ->where('user_id', $examiner->id)->whereIn('role', [ExaminerRole::First, ExaminerRole::Second])
            ->first();

        if ($assignment === null) {
            throw ValidationException::withMessages(['examiner' => 'You are not assigned as an examiner for this examination.']);
        }

        $source = $assignment->role === ExaminerRole::First ? MarkSource::Examiner1 : MarkSource::Examiner2;

        if (ItemMark::query()->where('candidate_exam_id', $attempt->id)->where('cand_paper_item_id', $item->id)->where('source', $source)->exists()) {
            throw ValidationException::withMessages(['item' => 'You have already marked this item.']);
        }

        if ($marksAwarded < 0 || $marksAwarded > $item->paperItem->marks) {
            throw ValidationException::withMessages(['marks_awarded' => 'The mark must be between 0 and the item\'s marks.']);
        }

        // Overturning a mark the computer made against a sealed key is a decision an examination
        // board may well ask about, so it is not allowed to be silent. Ordinary marking — an essay,
        // or confirming a short answer the computer only guessed at — needs no such justification.
        if ($this->overridesAMachineMark($item) && trim((string) $comments) === '') {
            throw ValidationException::withMessages([
                'comments' => 'Say why you are changing a mark the computer awarded.',
            ]);
        }

        if ($criteria !== [] && round(array_sum(array_column($criteria, 'marks_awarded')), 2) !== round($marksAwarded, 2)) {
            throw ValidationException::withMessages(['criteria' => 'The rubric criteria must add up to the mark given.']);
        }

        $mark = DB::transaction(function () use ($attempt, $item, $source, $marksAwarded, $criteria, $comments, $examiner): ItemMark {
            $mark = ItemMark::query()->create([
                'candidate_exam_id' => $attempt->id,
                'cand_paper_item_id' => $item->id,
                'source' => $source,
                'marks_awarded' => $marksAwarded,
                'max_marks' => $item->paperItem->marks,
                'marked_by' => $examiner->id,
                'comments' => $comments,
                'marked_at' => now(),
            ]);

            foreach ($criteria as $criterion) {
                $mark->criteria()->create($criterion);
            }

            return $mark;
        });

        if ($attempt->examination->require_double_marking) {
            $this->finalise->__invoke($item);
        }

        return $mark;
    }

    /**
     * Whether this mark replaces one the machine already settled — as opposed to marking something
     * the machine never touched, or confirming something it only suggested.
     */
    private function overridesAMachineMark(CandidatePaperItem $item): bool
    {
        $type = QuestionType::query()->find($item->paperItem->question_type_id);

        return $type !== null && ! $type->is_manually_marked && ! $type->requires_confirmation;
    }
}
