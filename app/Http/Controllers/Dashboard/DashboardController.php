<?php

namespace App\Http\Controllers\Dashboard;

use App\Domain\Identity\ActiveBranch;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Domain\QuestionBank\Queries\QuestionEditorData;
use App\Domain\QuestionBank\Queries\QuestionList;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What the campus's question bank looks like right now, for the people who may see it. Everything
 * counted here is limited to the campus being worked in and the user's exam access.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request, ActiveBranch $activeBranch, QuestionList $list, QuestionEditorData $editorData): Response
    {
        $user = $request->user();
        $branchId = $activeBranch->id($user);
        $canSee = $branchId !== null && $user->can('qbank.question.view');

        $counts = $canSee ? $list->statusCounts($user, $branchId) : [];
        $statuses = [];
        foreach ($canSee ? $list->statusGroupCounts($user, $branchId) : [] as $group) {
            $statuses[] = ['status' => $group['key'], 'label' => $group['label'], 'count' => $group['count']];
        }

        return Inertia::render('Dashboard', [
            // What is waiting for this person: their own reviews, and questions to decide about.
            'work' => [
                'myReviews' => $branchId !== null && ($user->can('qbank.review.perform') || $user->can('qbank.review.academic'))
                    ? ReviewAssignment::query()->where('reviewer_id', $user->id)->where('branch_id', $branchId)->where('status', 'open')->count()
                    : null,
                'toApprove' => $branchId !== null && $user->can('qbank.question.approve')
                    ? QuestionVersion::query()->where('branch_id', $branchId)->where('status', VersionStatus::UnderReview)->where('author_id', '!=', $user->id)->count()
                    : null,
            ],
            'questionBank' => [
                'visible' => $canSee,
                'total' => array_sum($counts),
                'statuses' => $statuses,
                'courses' => $canSee ? count($editorData->courses($user, $branchId)) : 0,
                'mine' => $canSee ? $list->mineCount($user, $branchId) : 0,
            ],
        ]);
    }
}
