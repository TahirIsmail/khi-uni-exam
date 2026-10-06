<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminTables;
use App\Support\Admin\RoleGrants;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Setup → Roles: the roles, and their "Question Bank & Exams" checkboxes — the same screen as
 * kmu-cms Roles → Assign Permission. A Super Admin role has every permission without checkboxes.
 */
final class RoleController extends Controller
{
    public function __construct(private readonly RoleGrants $roles) {}

    public function index(): Response
    {
        return Inertia::render('setup/Roles', $this->roles->overview());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:'.AdminTables::rule('roles').',name']]);
        $this->roles->create($data['name']);

        return $this->done('Role added. Tick what it may do.');
    }

    public function update(Request $request, int $role): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'unique:'.AdminTables::rule('roles').',name,'.$role],
            'grants' => ['array'],
            'grants.*' => ['array'],
            'grants.*.*' => ['string', 'in:'.implode(',', RoleGrants::BOXES)],
        ]);
        $this->roles->update($role, $data['name'], $data['grants'] ?? []);

        return $this->done('Role saved.');
    }

    public function destroy(int $role): RedirectResponse
    {
        if (! $this->roles->delete($role)) {
            return back()->withErrors(['role' => 'Only a role nobody has, and not the Super Admin role, can be deleted.']);
        }

        return $this->done('Role deleted.');
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
