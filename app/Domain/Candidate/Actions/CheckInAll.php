<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\CandidatePin;
use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Checks in every candidate of an examination who is not checked in yet (or the ticked ones), in one go — a hall of 200
 * should not be found one by one. With one exam PIN for everyone, everybody on the list is checked
 * in and no PIN is issued. Otherwise only seated candidates are (as when checking in one), each gets
 * a PIN of their own, and the PINs are returned once, to be printed and handed out.
 */
final class CheckInAll
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly CandidatePin $pins,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<int>|null  $only  the ticked candidates, or null for everybody
     * @return array{checkedIn: int, notSeated: int, pins: list<array{candidateNo: string, name: string, pin: string}>}
     */
    public function __invoke(User $user, Examination $examination, ?array $only = null): array
    {
        $this->guard->authorise($user, $examination, 'candidate.checkin', 'You cannot check candidates in for this course.');
        $shared = $examination->shared_pin !== null;
        $waiting = $shared ? [CandidateStatus::Enrolled->value, CandidateStatus::Allocated->value] : [CandidateStatus::Allocated->value];

        $pins = [];
        $count = 0;
        DB::transaction(function () use ($user, $examination, $shared, $waiting, $only, &$pins, &$count): void {
            Candidate::query()->where('examination_id', $examination->id)->whereIn('status', $waiting)
                ->when($only !== null, fn ($query) => $query->whereIn('id', $only === [] ? [0] : $only))
                ->orderBy('candidate_no')->lockForUpdate()->each(function (Candidate $candidate) use ($user, $shared, &$pins, &$count): void {
                    $pin = $shared ? null : $this->pins->generate();
                    $candidate->update([
                        'status' => CandidateStatus::CheckedIn->value,
                        'checked_in_by' => $user->id,
                        'checked_in_at' => now(),
                        'pin_hash' => $pin === null ? null : $this->pins->hash($pin),
                        'pin_issued_by' => $pin === null ? null : $user->id,
                        'pin_issued_at' => $pin === null ? null : now(),
                        'updated_by' => $user->id,
                    ]);
                    if ($pin !== null) {
                        $pins[] = ['candidateNo' => $candidate->candidate_no, 'name' => $candidate->name, 'pin' => $pin];
                    }
                    $count++;
                });

            if ($count > 0) {
                $this->audit->record('candidate.checked_in_all', 'examination', $examination->id, null, ['count' => $count], null, $user, $examination->branch_id);
            }
        });

        $notSeated = $shared ? 0 : Candidate::query()->where('examination_id', $examination->id)->where('status', CandidateStatus::Enrolled->value)->count();

        return ['checkedIn' => $count, 'notSeated' => $notSeated, 'pins' => $pins];
    }
}
