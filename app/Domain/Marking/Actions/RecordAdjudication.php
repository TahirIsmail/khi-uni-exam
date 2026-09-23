<?php

namespace App\Domain\Marking\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ItemMark;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The third opinion, for one item where two examiners disagreed beyond the threshold (exam phase,
 * step 20): the adjudicator's mark is itself the item's final mark — there is no further review.
 */
final class RecordAdjudication
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $adjudicator, CandidatePaperItem $item, float $marksAwarded, ?string $reason): ItemMark
    {
        $attempt = $item->candidateExam;
        $examination = $attempt->examination;

        $this->guard->authorise($adjudicator, $examination, 'marking.adjudicate', 'You cannot adjudicate this examination.');

        $marks = ItemMark::query()->where('candidate_exam_id', $attempt->id)->where('cand_paper_item_id', $item->id)
            ->get()->keyBy(fn (ItemMark $m): string => $m->source->value);

        if (! $marks->has(MarkSource::Examiner1->value) || ! $marks->has(MarkSource::Examiner2->value)) {
            throw ValidationException::withMessages(['item' => 'Both examiners must mark this item before it can be adjudicated.']);
        }
        if ($marks->has(MarkSource::Final->value) || $marks->has(MarkSource::Adjudicator->value)) {
            throw ValidationException::withMessages(['item' => 'This item does not need adjudication.']);
        }

        if ($marksAwarded < 0 || $marksAwarded > $item->paperItem->marks) {
            throw ValidationException::withMessages(['marks_awarded' => 'The mark must be between 0 and the item\'s marks.']);
        }

        return DB::transaction(function () use ($attempt, $item, $marksAwarded, $reason, $adjudicator): ItemMark {
            $mark = ItemMark::query()->create([
                'candidate_exam_id' => $attempt->id,
                'cand_paper_item_id' => $item->id,
                'source' => MarkSource::Adjudicator,
                'marks_awarded' => $marksAwarded,
                'max_marks' => $item->paperItem->marks,
                'marked_by' => $adjudicator->id,
                'comments' => $reason,
                'marked_at' => now(),
            ]);

            $this->audit->record('marking.adjudicated', 'cand_paper_item', $item->id, null, ['marks_awarded' => $marksAwarded], $reason, $adjudicator, $attempt->examination->branch_id);

            return $mark;
        });
    }
}
