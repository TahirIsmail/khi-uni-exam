<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Delivery\Models\DeliverySession;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves one answer change (ADR-0003): sequence-numbered and idempotent, so the browser can retry a
 * send it is not sure got through without ever double-applying it, and a device that comes back
 * online can safely resend everything it was still holding.
 */
final class RecordAnswer
{
    public function __construct(private readonly EnforceDeadline $enforceDeadline) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __invoke(CandidateExam $attempt, DeliverySession $session, CandidatePaperItem $item, int $sequenceNo, array $payload, bool $flagged, ?Carbon $clientTime): void
    {
        $attempt = $this->enforceDeadline->__invoke($attempt);

        if ($item->candidate_exam_id !== $attempt->id) {
            throw ValidationException::withMessages(['item' => 'That question is not part of this attempt.']);
        }
        if ($attempt->status === AttemptStatus::Paused) {
            throw ValidationException::withMessages(['attempt' => 'The room has been paused. Answers cannot be saved until it resumes.']);
        }
        if ($attempt->status !== AttemptStatus::InProgress) {
            throw ValidationException::withMessages(['attempt' => 'This attempt is no longer being answered.']);
        }

        DB::transaction(function () use ($attempt, $item, $sequenceNo, $payload, $flagged, $clientTime): void {
            try {
                DB::table('dlv_answer_events')->insert([
                    'candidate_exam_id' => $attempt->id,
                    'cand_paper_item_id' => $item->id,
                    'sequence_no' => $sequenceNo,
                    'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'flagged' => $flagged,
                    'client_time' => $clientTime,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // The same sequence number, sent again (a retried autosave): already recorded, so
                // there is nothing more to do — not an error.
                return;
            }

            $current = DB::table('dlv_answers_current')
                ->where('candidate_exam_id', $attempt->id)
                ->where('cand_paper_item_id', $item->id)
                ->lockForUpdate()
                ->first();

            $row = [
                'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'flagged' => $flagged,
                'sequence_no' => $sequenceNo,
                'updated_at' => now(),
            ];

            if ($current === null) {
                DB::table('dlv_answers_current')->insert([
                    'candidate_exam_id' => $attempt->id,
                    'cand_paper_item_id' => $item->id,
                    ...$row,
                ]);
            } elseif ($sequenceNo > $current->sequence_no) {
                // An older, out-of-order retry never overwrites a newer answer.
                DB::table('dlv_answers_current')
                    ->where('candidate_exam_id', $attempt->id)
                    ->where('cand_paper_item_id', $item->id)
                    ->update($row);
            }
        }, 3); // retried on a deadlock: a hall saving answers at once

        $session->update(['last_heartbeat_at' => now()]);
    }
}
