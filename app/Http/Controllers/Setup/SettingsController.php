<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminTables;
use App\Support\Admin\ModuleSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup's front page: the campuses and the module settings that kmu-cms keeps in
 * "Question Bank & Exams → Exam Module Settings".
 */
final class SettingsController extends Controller
{
    public function __construct(private readonly ModuleSettings $settings) {}

    public function index(): Response
    {
        return Inertia::render('setup/Index', $this->settings->overview());
    }

    public function update(Request $request): RedirectResponse
    {
        $this->settings->save($request->validate([
            'mfa' => ['boolean'],
            'reviews_required' => ['required', 'integer', 'min:1', 'max:5'],
            'review_days' => ['required', 'integer', 'min:1', 'max:60'],
            'auto_activate' => ['boolean'],
            'accept_stores' => ['boolean'],
            'academic_review' => ['boolean'],
            'anonymous' => ['boolean'],
            'device_approval' => ['boolean'],
        ]));

        return $this->done('Settings saved.');
    }

    public function storeBranch(Request $request): RedirectResponse
    {
        $this->settings->saveBranch(null, $this->branchInput($request, null));

        return $this->done('Campus added.');
    }

    public function updateBranch(Request $request, int $branch): RedirectResponse
    {
        $this->settings->saveBranch($branch, $this->branchInput($request, $branch));

        return $this->done('Campus saved.');
    }

    /** @return array{branch_name: string, branch_code: string, status: string} */
    private function branchInput(Request $request, ?int $id): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'code' => ['required', 'string', 'max:50', 'unique:'.AdminTables::rule('branches').',branch_code'.($id ? ','.$id : '')],
            'is_active' => ['boolean'],
        ]);

        return ['branch_name' => $data['name'], 'branch_code' => $data['code'], 'status' => ($data['is_active'] ?? true) ? 'active' : 'inactive'];
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
