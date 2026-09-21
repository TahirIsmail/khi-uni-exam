<?php

namespace App\Http\Controllers\Exam;

use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\ActiveBranch;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * What every examination screen shares: the campus being worked in, and a check that the
 * examination is in it and in a place the user's rights and exam access reach.
 */
abstract class ExamAreaController extends Controller
{
    public function __construct(
        protected readonly ActiveBranch $activeBranch,
        protected readonly AccessControl $access,
    ) {}

    protected function branchId(Request $request): int
    {
        return $this->activeBranch->id($request->user()) ?? abort(403, 'You do not work in any campus.');
    }

    /** An examination of another campus does not exist for this user; one they may not see is forbidden. */
    protected function guard(Request $request, Examination $examination): void
    {
        abort_unless($examination->branch_id === $this->branchId($request), 404);

        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);
        $user = $request->user();
        abort_unless(
            $this->access->allows($user, 'exam.view', $target) || $this->access->allows($user, 'exam.blueprint.view', $target),
            403,
            'You cannot open the examinations of this course.',
        );
    }
}
