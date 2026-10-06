<?php

namespace App\Domain\Marking\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Actions\CandidateGuard;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Enums\ExaminerRole;
use App\Domain\Marking\Models\ExaminerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Assigning who marks or adjudicates an examination's manually-marked items (exam phase, step 20).
 * First and second examiner are each one person; nobody is assigned both roles on the same
 * examination, so the second opinion is never the first one repeating itself.
 */
final class AssignExaminer
{
    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $actor, Examination $examination, User $examiner, ExaminerRole $role): ExaminerAssignment
    {
        $this->guard->authorise($actor, $examination, 'marking.assign', 'You cannot assign examiners for this course.');

        return DB::transaction(function () use ($examination, $examiner, $role, $actor): ExaminerAssignment {
            if ($role !== ExaminerRole::Adjudicator) {
                $other = $role === ExaminerRole::First ? ExaminerRole::Second : ExaminerRole::First;
                $alreadyOther = ExaminerAssignment::query()->where('examination_id', $examination->id)
                    ->where('role', $other)->where('user_id', $examiner->id)->exists();
                if ($alreadyOther) {
                    throw ValidationException::withMessages(['user_id' => 'This person is already the other examiner for this examination.']);
                }

                ExaminerAssignment::query()->where('examination_id', $examination->id)->where('role', $role)->delete();
            }

            $assignment = ExaminerAssignment::query()->create([
                'examination_id' => $examination->id,
                'user_id' => $examiner->id,
                'role' => $role,
                'assigned_by' => $actor->id,
                'assigned_at' => now(),
            ]);

            $this->audit->record('marking.examiner_assigned', 'examination', $examination->id, null, ['user_id' => $examiner->id, 'role' => $role->value], null, $actor, $examination->branch_id);

            return $assignment;
        });
    }
}
