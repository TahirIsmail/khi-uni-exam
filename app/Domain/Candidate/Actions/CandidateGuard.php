<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * What every candidate action has to satisfy: the right, for this examination's own course and
 * campus — the same scoping blueprints and papers already check.
 */
final class CandidateGuard
{
    public function __construct(private readonly AccessControl $access) {}

    public function authorise(User $user, Examination $examination, string $permission, string $message): void
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);
        if (! $this->access->allows($user, $permission, $target)) {
            throw new AuthorizationException($message);
        }
    }
}
