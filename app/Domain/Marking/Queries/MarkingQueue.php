<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Enums\ExaminerRole;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ExaminerAssignment;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What one examiner still has to mark for an examination: every item of every submitted attempt
 * they have not yet marked that either nobody can mark by machine (an essay) or that the machine has
 * only guessed at (a short answer, a cloze blank) — and, blind, whether their peer has marked it
 * (never what the peer gave).
 */
final class MarkingQueue
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forExaminer(Examination $examination, User $examiner): array
    {
        $assignment = ExaminerAssignment::query()->where('examination_id', $examination->id)
            ->where('user_id', $examiner->id)->whereIn('role', [ExaminerRole::First, ExaminerRole::Second])
            ->first();

        if ($assignment === null) {
            return [];
        }

        $mySource = $assignment->role === ExaminerRole::First ? MarkSource::Examiner1 : MarkSource::Examiner2;
        $peerSource = $assignment->role === ExaminerRole::First ? MarkSource::Examiner2 : MarkSource::Examiner1;

        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->with(['candidate', 'items.paperItem'])->get();

        // Two kinds of item need this examiner: one nobody can mark by machine, and one the machine
        // has already guessed at and must not be trusted on.
        $manualTypeIds = QuestionType::query()->where('is_manually_marked', true)->pluck('id');
        $confirmTypeIds = QuestionType::query()->where('requires_confirmation', true)->pluck('id');

        $marks = ItemMark::query()->whereIn('candidate_exam_id', $attempts->pluck('id'))->get()
            ->groupBy('cand_paper_item_id');

        $rows = [];
        foreach ($attempts as $attempt) {
            foreach ($attempt->items as $item) {
                $typeId = $item->paperItem->question_type_id;
                $needsConfirming = $confirmTypeIds->contains($typeId);

                if (! $manualTypeIds->contains($typeId) && ! $needsConfirming) {
                    continue;
                }

                /** @var Collection<int, ItemMark> $itemMarks */
                $itemMarks = $marks->get($item->id, collect());
                $bySource = $itemMarks->keyBy(fn (ItemMark $m): string => $m->source->value);

                if ($bySource->has($mySource->value)) {
                    continue;
                }

                $auto = $bySource->get(MarkSource::Auto->value);

                $rows[] = [
                    'attemptId' => $attempt->id,
                    'itemId' => $item->id,
                    'candidateNo' => $attempt->candidate->candidate_no,
                    'position' => $item->position,
                    'marks' => (float) $item->paperItem->marks,
                    'peerHasMarked' => $bySource->has($peerSource->value),
                    // What the computer made of the typed answer, for the screen to fill in. Null
                    // for an essay, which nothing has guessed at.
                    'suggestedMarks' => $needsConfirming && $auto !== null ? $auto->marks_awarded : null,
                    'needsConfirming' => $needsConfirming,
                ];
            }
        }

        return $rows;
    }
}
