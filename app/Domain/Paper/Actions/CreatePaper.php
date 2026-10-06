<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use App\Domain\Paper\Models\Paper;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Starts the paper of an examination, once its blueprint is approved. There is one paper to begin
 * with; a paper that has been finalised is replaced by a new version in the next step.
 */
final class CreatePaper
{
    public function __construct(
        private readonly PaperGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $user, Examination $examination): Paper
    {
        $this->guard->authorise($user, $examination);
        $this->guard->blueprintMustBeApproved($examination);

        return DB::transaction(function () use ($user, $examination): Paper {
            // Serialised on the examination, so a double click does not make two papers.
            Examination::query()->whereKey($examination->id)->lockForUpdate()->firstOrFail();
            if (Paper::query()->where('examination_id', $examination->id)->exists()) {
                throw ValidationException::withMessages(['paper' => 'This examination already has a paper.']);
            }

            $paper = Paper::query()->create([
                'examination_id' => $examination->id,
                'version_no' => 1,
                'status' => PaperStatus::Draft,
                'blueprint_hash' => Blueprint::query()->where('examination_id', $examination->id)->value('approved_hash'),
                'created_by' => $user->id,
            ]);

            $this->audit->record('paper.created', 'paper', $paper->id, null, [
                'examination' => $examination->public_ref,
                'version_no' => 1,
            ], null, $user, $examination->branch_id);

            return $paper;
        });
    }
}
