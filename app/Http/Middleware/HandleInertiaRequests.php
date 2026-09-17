<?php

namespace App\Http\Middleware;

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
                'user' => $request->user(),
                // Only what the navigation needs; every route and action checks the permission again.
                'can' => $request->user() === null ? [] : [
                    'viewQuestions' => $request->user()->can('qbank.question.view'),
                    'createQuestions' => $request->user()->can('qbank.question.create'),
                ],
            ],
            // The campus being worked in, and the user's campuses for the switcher.
            'branch' => $request->user() === null ? null : [
                'id' => $this->activeBranch->id($request->user()),
                'name' => $this->activeBranch->name($request->user()),
                'options' => $this->activeBranch->options($request->user()),
            ],
            // "Back to CMS" link.
            'cmsUrl' => rtrim((string) config('services.kmu_cms.url'), '/').'/admin/admin/dashboard',
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
