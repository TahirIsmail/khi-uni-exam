<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\DeliverySession;
use Illuminate\Validation\ValidationException;

/**
 * "Still here": sent by the candidate's browser every few seconds while the exam is open, so a
 * second sign-in elsewhere can tell this computer is still working (ADR-0003). Also the moment the
 * deadline is checked, so time running out is caught even between answers.
 */
final class Heartbeat
{
    public function __construct(private readonly EnforceDeadline $enforceDeadline) {}

    public function __invoke(CandidateExam $attempt, DeliverySession $session): CandidateExam
    {
        if ($session->ended_at !== null) {
            throw ValidationException::withMessages(['session' => 'This exam is now open on another computer.']);
        }

        $session->update(['last_heartbeat_at' => now()]);

        return $this->enforceDeadline->__invoke($attempt);
    }
}
