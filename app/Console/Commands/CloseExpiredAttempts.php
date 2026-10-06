<?php

namespace App\Console\Commands;

use App\Domain\Delivery\Actions\EnforceDeadline;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The deadline is normally enforced the moment a heartbeat or an answer touches an attempt
 * (App\Domain\Delivery\Actions\EnforceDeadline); this is the safety net for one nobody calls back
 * into at all — a candidate who simply closed the laptop and never came back (scheduled frequently).
 */
#[Signature('exam:close-expired-attempts')]
#[Description('Auto-submit attempts whose deadline and grace period have passed with nobody calling back in')]
final class CloseExpiredAttempts extends Command
{
    public function handle(EnforceDeadline $enforceDeadline): int
    {
        $graceSeconds = (int) config('exam.delivery.grace_seconds');

        $attempts = CandidateExam::query()
            ->where('status', AttemptStatus::InProgress)
            ->whereNotNull('deadline_at')
            ->where('deadline_at', '<', now()->subSeconds($graceSeconds))
            ->get();

        foreach ($attempts as $attempt) {
            $enforceDeadline($attempt);
        }

        $this->info("Closed {$attempts->count()} expired attempt(s).");

        return self::SUCCESS;
    }
}
