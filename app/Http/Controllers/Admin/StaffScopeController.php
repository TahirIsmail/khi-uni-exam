<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\ManageUserScopes;
use App\Domain\Identity\Queries\StaffDirectory;
use App\Domain\Identity\Queries\StaffScopeDetails;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserScopeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class StaffScopeController extends Controller
{
    public function index(Request $request, StaffDirectory $directory): Response
    {
        $search = $request->validate(['search' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return Inertia::render('admin/Staff', [
            'staff' => $directory->search($request->user(), $search['search'] ?? null),
            'search' => $search['search'] ?? '',
        ]);
    }

    public function show(Request $request, int $staff, ManageUserScopes $scopes, StaffScopeDetails $details): Response
    {
        $actor = $request->user();

        return Inertia::render('admin/StaffScopes', $details->for($actor, $scopes->subject($actor, $staff)));
    }

    public function store(StoreUserScopeRequest $request, int $staff, ManageUserScopes $scopes): RedirectResponse
    {
        $scopeId = $request->validated('scope_id');
        $scopes->add($request->user(), $staff, (string) $request->validated('scope_type'), $scopeId === null ? null : (int) $scopeId);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scope added.')]);

        return to_route('admin.staff.show', $staff);
    }

    public function destroy(Request $request, int $staff, int $scope, ManageUserScopes $scopes): RedirectResponse
    {
        $scopes->remove($request->user(), $staff, $scope);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Scope removed.')]);

        return to_route('admin.staff.show', $staff);
    }
}
