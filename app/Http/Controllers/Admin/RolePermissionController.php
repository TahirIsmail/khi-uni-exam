<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Identity\Actions\UpdateRolePermissions;
use App\Domain\Identity\Queries\RolePermissionMatrix;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateRolePermissionsRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class RolePermissionController extends Controller
{
    public function index(Request $request, RolePermissionMatrix $matrix): Response
    {
        return Inertia::render('admin/Roles', $matrix->forActor($request->user()));
    }

    public function update(UpdateRolePermissionsRequest $request, int $role, UpdateRolePermissions $update): RedirectResponse
    {
        $update($request->user(), $role, $request->permissions(), $request->validated('reason'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role permissions saved.')]);

        return to_route('admin.roles.index');
    }
}
