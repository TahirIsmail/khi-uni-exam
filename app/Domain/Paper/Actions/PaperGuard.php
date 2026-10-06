<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Paper\Models\Paper;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * What every change to a paper has to satisfy: the person may choose questions for this course, the
 * blueprint is approved (a paper is built to an approved blueprint, and nothing else), and the paper
 * is still a draft.
 */
final class PaperGuard
{
    public function __construct(private readonly AccessControl $access) {}

    public function authorise(User $user, Examination $examination, string $permission = 'exam.select_questions'): void
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);
        if (! $this->access->allows($user, $permission, $target)) {
            throw new AuthorizationException('You cannot choose questions for the papers of this course.');
        }
    }

    public function blueprintMustBeApproved(Examination $examination): void
    {
        $status = DB::table('exm_blueprints')->where('examination_id', $examination->id)->value('status');

        if ($status !== BlueprintStatus::Approved->value) {
            throw ValidationException::withMessages(['paper' => 'The blueprint has to be approved first, and it is not (it may have been reopened). The paper is built to an approved blueprint.']);
        }
    }

    public function mustBeEditable(Examination $examination, Paper $paper): void
    {
        $this->blueprintMustBeApproved($examination);

        if (! $paper->status->isEditable()) {
            throw ValidationException::withMessages(['paper' => 'This paper can no longer be changed.']);
        }
    }
}
