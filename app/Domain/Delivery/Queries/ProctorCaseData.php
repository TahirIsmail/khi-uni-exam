<?php

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\ProctorDecision;
use App\Domain\Delivery\Models\ProctorEvent;

/**
 * One candidate's proctoring case, for the committee's review screen: every event reported during
 * their attempt, and every decision already recorded against it.
 */
final class ProctorCaseData
{
    /**
     * @return array<string, mixed>
     */
    public function forAttempt(CandidateExam $attempt): array
    {
        $events = ProctorEvent::query()->where('candidate_exam_id', $attempt->id)->orderBy('occurred_at')->get();
        $decisions = ProctorDecision::query()->where('candidate_exam_id', $attempt->id)->with('decidedBy')->orderByDesc('decided_at')->get();

        return [
            'attempt' => [
                'id' => $attempt->id,
                'candidateNo' => $attempt->candidate->candidate_no,
                'name' => $attempt->candidate->name,
                'status' => $attempt->status->value,
                'statusLabel' => $attempt->status->label(),
            ],
            'events' => $events->map(fn (ProctorEvent $event): array => [
                'id' => $event->id,
                'type' => $event->type->value,
                'typeLabel' => $event->type->label(),
                'severity' => $event->severity->value,
                'detail' => $event->detail,
                'occurredAt' => $event->occurred_at->toIso8601String(),
            ])->values()->all(),
            'decisions' => $decisions->map(fn (ProctorDecision $decision): array => [
                'id' => $decision->id,
                'decision' => $decision->decision->value,
                'decisionLabel' => $decision->decision->label(),
                'reason' => $decision->reason,
                'decidedBy' => $decision->decidedBy?->name,
                'decidedAt' => $decision->decided_at->toIso8601String(),
                'coversFrom' => $decision->covers_from?->toIso8601String(),
                'coversTo' => $decision->covers_to?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
