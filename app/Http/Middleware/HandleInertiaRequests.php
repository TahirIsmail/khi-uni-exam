<?php

namespace App\Http\Middleware;

use App\Domain\Exam\Queries\ExaminationList;
use App\Domain\Identity\ActiveBranch;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(private readonly ActiveBranch $activeBranch) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user('web'),
                // Only what the navigation needs; every route and action checks the permission again.
                'can' => $request->user('web') === null ? [] : [
                    'viewQuestions' => $request->user('web')->can('qbank.question.view'),
                    'createQuestions' => $request->user('web')->can('qbank.question.create'),
                    'importQuestions' => $request->user('web')->can('qbank.import.run'),
                    'reviewQuestions' => $request->user('web')->can('qbank.review.perform') || $request->user('web')->can('qbank.review.academic') || $request->user('web')->can('qbank.question.approve'),
                    'approveQuestions' => $request->user('web')->can('qbank.question.approve'),
                    'viewExams' => $request->user('web')->can('exam.access'),
                    'createExams' => $request->user('web')->can('exam.create'),
                    'approveBlueprints' => $request->user('web')->can('exam.blueprint.approve'),
                    'conductExams' => $request->user('web')->can('exam.conduct.access'),
                    'markExams' => $request->user('web')->can('marking.assign') || $request->user('web')->can('marking.mark') || $request->user('web')->can('marking.adjudicate'),
                    'viewResults' => $request->user('web')->can('result.view') || $request->user('web')->can('result.approve') || $request->user('web')->can('result.publish') || $request->user('web')->can('result.components'),
                    'viewReports' => $request->user('web')->can('report.view'),
                    'manageSetup' => $request->user('web')->can('setup.manage'),
                ],
                // What is waiting for this person, for the badge in the menu. Worked out only for those who approve.
                'awaiting' => [
                    'blueprints' => fn (): int => $this->awaitingBlueprints($request),
                ],
            ],
            // The campus being worked in, and the user's campuses for the switcher.
            'branch' => $request->user('web') === null ? null : [
                'id' => $this->activeBranch->id($request->user('web')),
                'name' => $this->activeBranch->name($request->user('web')),
                'options' => $this->activeBranch->options($request->user('web')),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** Blueprints waiting for the signed-in person to approve; none for a guest or for somebody who does not approve. */
    private function awaitingBlueprints(Request $request): int
    {
        $user = $request->user('web');
        $branchId = $user === null ? null : $this->activeBranch->id($user);

        return $user !== null && $branchId !== null && $user->can('exam.blueprint.approve')
            ? app(ExaminationList::class)->awaitingApproval($user, $branchId)
            : 0;
    }
}
