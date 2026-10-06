<?php

namespace App\Http\Controllers\Setup;

use App\Http\Controllers\Controller;
use App\Support\Admin\AdminTables;
use App\Support\Admin\StaffAccounts;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** Setup → Staff: who can sign in, with which roles, in which campus, and limited to which courses. */
final class StaffController extends Controller
{
    public function __construct(private readonly StaffAccounts $accounts) {}

    public function index(): Response
    {
        return Inertia::render('setup/Staff', $this->accounts->overview());
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $user = $this->accounts->save(null, $data, $data['password']);
        $this->accounts->limitToCourses((int) $user->cms_staff_id, $data['course_ids'], $request->user('web')?->cms_staff_id);

        return $this->done('Staff member added. They can sign in now.');
    }

    public function update(Request $request, int $staff): RedirectResponse
    {
        abort_unless($this->accounts->exists($staff), 404);
        $data = $this->validated($request, false);

        // Nobody can lock themselves out of Setup.
        if ($request->user('web')?->cms_staff_id === $staff && (! $data['is_active'] || ! $this->accounts->includesSuperAdmin($data['role_ids']))) {
            return back()->withErrors(['role_ids' => 'You cannot switch off your own account or take away your own Super Admin role.']);
        }

        $this->accounts->save($staff, $data, $data['password'] ?? null);
        $this->accounts->limitToCourses($staff, $data['course_ids'], $request->user('web')?->cms_staff_id);

        return $this->done('Staff member saved.');
    }

    /** @return array{name: string, surname: string, email: string, contact_no: string, branch_id: int, is_active: bool, role_ids: list<int>, course_ids: list<int>, password: string|null} */
    private function validated(Request $request, bool $creating): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'surname' => ['nullable', 'string', 'max:200'],
            'email' => ['required', 'email', 'max:200'],
            'phone' => ['nullable', 'string', 'max:50'],
            'branch_id' => ['required', 'integer', Rule::exists(AdminTables::rule('branches'), 'id')],
            'is_active' => ['boolean'],
            'role_ids' => ['required', 'array', 'min:1'],
            'role_ids.*' => ['integer', Rule::exists(AdminTables::rule('roles'), 'id')],
            'course_ids' => ['array'],
            'course_ids.*' => ['integer', Rule::exists(AdminTables::rule('acad_courses'), 'id')],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'min:8', 'max:200'],
        ], ['role_ids.required' => 'Give the staff member at least one role.']);

        return [
            'name' => $data['name'],
            'surname' => $data['surname'] ?? '',
            'email' => $data['email'],
            'contact_no' => $data['phone'] ?? '',
            'branch_id' => (int) $data['branch_id'],
            'is_active' => (bool) ($data['is_active'] ?? true),
            'role_ids' => array_values(array_map(intval(...), $data['role_ids'])),
            'course_ids' => array_values(array_map(intval(...), $data['course_ids'] ?? [])),
            'password' => $data['password'] ?? null,
        ];
    }

    private function done(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back();
    }
}
