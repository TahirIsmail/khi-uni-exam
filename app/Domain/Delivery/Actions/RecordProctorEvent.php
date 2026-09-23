<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Delivery\Enums\ProctorEventType;
use App\Domain\Delivery\Enums\ProctorSeverity;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\DeliverySession;
use App\Domain\Delivery\Models\ProctorEvent;

/**
 * A candidate's browser reporting something during lockdown (exam phase, step 19): the event is
 * kept exactly as reported, its severity fixed by type (ProctorEventType::severity()), not chosen
 * here — so the same kind of event always weighs the same, whoever's watching. A high-severity event
 * is also audited, so it surfaces in the tamper-evident log as well as the case review screen.
 */
final class RecordProctorEvent
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $detail
     */
    public function __invoke(CandidateExam $attempt, ?DeliverySession $session, ProctorEventType $type, array $detail = []): ProctorEvent
    {
        $severity = $type->severity();

        $event = ProctorEvent::query()->create([
            'candidate_exam_id' => $attempt->id,
            'session_id' => $session?->id,
            'type' => $type,
            'severity' => $severity,
            'detail' => $detail === [] ? null : $detail,
            'occurred_at' => now(),
        ]);

        if ($severity === ProctorSeverity::High) {
            $this->audit->record('proctor.event_recorded', 'candidate_exam', $attempt->id, null, ['type' => $type->value, 'severity' => $severity->value], null, null, $attempt->examination->branch_id);
        }

        return $event;
    }
}
