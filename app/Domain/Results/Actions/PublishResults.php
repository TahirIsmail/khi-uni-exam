<?php

namespace App\Domain\Results\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ResultPublication;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Publishing an examination's results — a separate right from approving them, so two people are
 * involved before anyone outside sees a result (exam phase, step 21).
 */
final class PublishResults
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination): ResultPublication
    {
        $this->guard->authorise($user, $examination, 'result.publish', 'You cannot publish results for this course.');

        $publication = ResultPublication::query()->where('examination_id', $examination->id)->first();
        if ($publication === null || $publication->status !== PublicationStatus::Approved) {
            throw ValidationException::withMessages(['results' => 'Results must be approved before they can be published.']);
        }

        return DB::transaction(function () use ($publication, $examination, $user): ResultPublication {
            $publication->update(['status' => PublicationStatus::Published, 'published_by' => $user->id, 'published_at' => now()]);

            $this->audit->record('result.published', 'examination', $examination->id, null, null, null, $user, $examination->branch_id);

            return $publication;
        });
    }
}
