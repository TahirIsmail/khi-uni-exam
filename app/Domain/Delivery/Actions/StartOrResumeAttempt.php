<?php

namespace App\Domain\Delivery\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\CandidatePin;
use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\DeliverySession;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Signing in to sit an exam (ADR-0003): the candidate number and PIN identify the attempt, not a
 * kmu-cms account. What happens next depends on where the attempt already stands and whether
 * another computer is already using it — never on which computer this is.
 */
final class StartOrResumeAttempt
{
    public function __construct(
        private readonly CandidatePin $pins,
        private readonly AssignPaperItems $assignItems,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{outcome: 'ready'|'submitted', attempt: CandidateExam, session: ?DeliverySession}
     */
    public function __invoke(Examination $examination, string $candidateNo, string $pin, ?string $ip, ?string $device): array
    {
        $candidate = Candidate::query()
            ->where('examination_id', $examination->id)
            ->where('candidate_no', trim($candidateNo))
            ->first();

        // An examination with one exam PIN for everyone needs no check-in: being on its list and
        // knowing the PIN is enough. Otherwise each candidate has their own PIN from check-in.
        $sharedPin = $examination->shared_pin;
        $recognised = $candidate !== null && ($sharedPin !== null
            ? hash_equals($sharedPin, trim($pin))
            : $candidate->status === CandidateStatus::CheckedIn && $candidate->pin_hash !== null && $this->pins->verify($pin, $candidate->pin_hash));
        if (! $recognised) {
            // The same message whichever part is wrong: a wrong PIN and an unknown candidate number
            // must not be told apart from the outside.
            throw ValidationException::withMessages(['pin' => 'That candidate number and PIN were not recognised.']);
        }

        return DB::transaction(function () use ($examination, $candidate, $ip, $device): array {
            $attempt = CandidateExam::query()
                ->where('candidate_id', $candidate->id)
                ->where('examination_id', $examination->id)
                ->lockForUpdate()
                ->first();

            if ($attempt === null || $attempt->status === AttemptStatus::NotStarted) {
                // Starting needs the examination open; coming back to one already started does not.
                $this->mustBeOpen($examination);
            }
            if ($attempt === null) {
                $attempt = $this->startFresh($examination, $candidate);
            }

            if ($attempt->status === AttemptStatus::Submitted) {
                return ['outcome' => 'submitted', 'attempt' => $attempt, 'session' => null];
            }

            $deviceChanged = $this->endStaleSessionIfAny($attempt);

            if ($attempt->status === AttemptStatus::NotStarted) {
                $this->assignItems->__invoke($attempt, $attempt->paper);
                $attempt->update([
                    'status' => AttemptStatus::InProgress,
                    'started_at' => now(),
                    'deadline_at' => $this->deadline($examination, $attempt->extra_seconds),
                ]);
                // Written once the work is committed: the audit chain's lock is then held for the entry alone,
                // not for the whole sign-in, so a hall starting together does not queue on it.
                DB::afterCommit(fn () => $this->audit->record('candidate.exam_started', 'candidate_exam', $attempt->id, null, ['candidate_id' => $candidate->id, 'examination_id' => $examination->id], null, null, $examination->branch_id));
            }

            $session = DeliverySession::query()->create([
                'candidate_exam_id' => $attempt->id,
                'token_hash' => hash('sha256', Str::random(40)),
                'device' => $device === null ? null : mb_substr($device, 0, 255),
                'ip' => $ip,
                'started_at' => now(),
                'last_heartbeat_at' => now(),
            ]);

            if ($deviceChanged) {
                // Written once the work is committed: the audit chain's lock is then held for the entry alone,
                // not for the whole sign-in, so a hall starting together does not queue on it.
                DB::afterCommit(fn () => $this->audit->record('candidate.device_changed', 'candidate_exam', $attempt->id, null, ['ip' => $ip], null, null, $examination->branch_id));
            }

            return ['outcome' => 'ready', 'attempt' => $attempt->fresh(), 'session' => $session];
        }, 3); // retried on a deadlock: many candidates starting or finishing at the same moment
    }

    /**
     * An examination with a closing time is open only from its start until it closes. (One without
     * stays as it was: the invigilator decides when it starts.)
     */
    private function mustBeOpen(Examination $examination): void
    {
        if ($examination->closes_at === null) {
            return;
        }
        $zone = (string) config('exam.timezone');
        if ($examination->starts_at !== null && now()->lessThan($examination->starts_at)) {
            throw ValidationException::withMessages(['pin' => 'This examination opens at '.$examination->starts_at->setTimezone($zone)->format('g:i A \o\n j M').'.']);
        }
        if (now()->greaterThanOrEqualTo($examination->closes_at)) {
            throw ValidationException::withMessages(['pin' => 'This examination closed at '.$examination->closes_at->setTimezone($zone)->format('g:i A \o\n j M').'.']);
        }
    }

    /** The full time from now, but never past the examination's closing time (extra time included). */
    private function deadline(Examination $examination, int $extraSeconds): CarbonInterface
    {
        $deadline = now()->addMinutes($examination->duration_minutes)->addSeconds($extraSeconds);
        $closes = $examination->closes_at?->copy()->addSeconds($extraSeconds);

        return $closes !== null && $closes->lessThan($deadline) ? $closes : $deadline;
    }

    private function startFresh(Examination $examination, Candidate $candidate): CandidateExam
    {
        $paper = Paper::query()->where('examination_id', $examination->id)->where('status', PaperStatus::Published)->latest('version_no')->first();
        if ($paper === null) {
            throw ValidationException::withMessages(['pin' => 'This examination is not open for delivery yet. Please wait for your invigilator.']);
        }

        return CandidateExam::query()->create([
            'candidate_id' => $candidate->id,
            'examination_id' => $examination->id,
            'paper_id' => $paper->id,
            'status' => AttemptStatus::NotStarted,
            'extra_seconds' => ($candidate->extra_time_minutes ?? 0) * 60,
        ]);
    }

    /** True when a previous, now-stale session was ended to make way for this one. */
    private function endStaleSessionIfAny(CandidateExam $attempt): bool
    {
        $existing = DeliverySession::query()->where('candidate_exam_id', $attempt->id)->whereNull('ended_at')->lockForUpdate()->first();
        if ($existing === null) {
            return false;
        }

        if ($existing->isAlive((int) config('exam.delivery.session_stale_after_seconds'))) {
            throw ValidationException::withMessages(['session' => 'This exam is open on another computer. If that computer has stopped working, ask your invigilator.']);
        }

        $existing->update(['ended_at' => now(), 'end_reason' => 'replaced']);

        return true;
    }
}
