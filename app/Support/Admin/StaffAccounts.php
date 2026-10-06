<?php

namespace App\Support\Admin;

use App\Domain\Identity\Actions\LinkCmsStaff;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Models\CmsStaff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/**
 * A staff member's account, kept the way kmu-cms keeps it — a row in the admin database's `staff`
 * table with its roles in `staff_roles` — plus the local user that signs in with a password here.
 */
final class StaffAccounts
{
    public function __construct(
        private readonly LinkCmsStaff $link,
        private readonly AccessControl $access,
    ) {}

    /**
     * @param  array{name: string, surname?: string, email: string, contact_no?: string|null, branch_id: int, is_active: bool, role_ids: list<int>}  $data
     */
    public function save(?int $staffId, array $data, ?string $password): User
    {
        $email = mb_strtolower(trim($data['email']));

        $taken = AdminTables::query('staff')->where('email', $email)->when($staffId, fn ($q) => $q->where('id', '!=', $staffId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['email' => 'Another staff member already has this email.']);
        }

        $staffId = DB::transaction(function () use ($staffId, $data, $email): int {
            $row = [
                'name' => trim($data['name']),
                'surname' => trim($data['surname'] ?? ''),
                'email' => $email,
                'contact_no' => trim((string) ($data['contact_no'] ?? '')),
                'branch_id' => $data['branch_id'],
                'is_active' => $data['is_active'] ? 1 : 0,
            ];

            if ($staffId === null) {
                $staffId = (int) AdminTables::query('staff')->insertGetId($row + ['employee_id' => 'STF-'.bin2hex(random_bytes(4))]);
            } else {
                AdminTables::query('staff')->where('id', $staffId)->update($row);
            }

            AdminTables::query('staff_roles')->where('staff_id', $staffId)->delete();
            foreach (array_unique($data['role_ids']) as $roleId) {
                AdminTables::query('staff_roles')->insert(['staff_id' => $staffId, 'role_id' => $roleId, 'is_active' => 1]);
            }

            return $staffId;
        });

        // Read back through the views (the `cms` connection) once the rows are committed.
        $user = ($this->link)(CmsStaff::query()->findOrFail($staffId));

        $user->forceFill(['is_active' => (bool) $data['is_active']]);
        if ($password !== null && $password !== '') {
            $user->forceFill(['password' => Hash::make($password)]);
        }
        $user->save();
        $this->access->forget($user);

        return $user;
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function overview(): array
    {
        $roles = AdminTables::query('staff_roles')->get(['staff_id', 'role_id'])->groupBy('staff_id');
        $scopes = AdminTables::query('acad_staff_exam_scopes')->get(['staff_id', 'scope_type', 'scope_id'])->groupBy('staff_id');
        $users = User::query()->whereNotNull('cms_staff_id')->get(['cms_staff_id', 'password', 'last_login_at'])->keyBy('cms_staff_id');

        return [
            'staff' => AdminTables::query('staff')->orderBy('name')->get()->map(fn ($s) => [
                'id' => (int) $s->id,
                'name' => $s->name,
                'surname' => $s->surname,
                'email' => $s->email,
                'phone' => $s->contact_no,
                'branchId' => $s->branch_id === null ? null : (int) $s->branch_id,
                'isActive' => (int) $s->is_active === 1,
                'roleIds' => ($roles[$s->id] ?? collect())->pluck('role_id')->map(fn ($id) => (int) $id)->values()->all(),
                'courseIds' => ($scopes[$s->id] ?? collect())->where('scope_type', 'course')->pluck('scope_id')->map(fn ($id) => (int) $id)->values()->all(),
                'hasPassword' => $users->get($s->id)?->password !== null,
                'lastLogin' => $users->get($s->id)?->last_login_at?->format('d M Y H:i'),
            ])->values()->all(),
            'roles' => AdminTables::query('roles')->orderBy('name')->get(['id', 'name', 'is_superadmin'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'name' => $r->name, 'isSuperAdmin' => (int) $r->is_superadmin === 1])->values()->all(),
            'branches' => AdminTables::query('branches')->orderBy('id')->get(['id', 'branch_name'])
                ->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->branch_name])->values()->all(),
            'courses' => AdminTables::query('acad_courses as c')->join(AdminTables::name('classes as p'), 'p.id', '=', 'c.class_id')
                ->orderBy('p.class')->orderBy('c.course_code')->get(['c.id', 'c.course_code', 'c.title', 'p.class as programme'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'label' => "{$c->programme} — {$c->course_code} {$c->title}"])->values()->all(),
        ];
    }

    public function exists(int $staffId): bool
    {
        return AdminTables::query('staff')->where('id', $staffId)->exists();
    }

    /** @param list<int> $roleIds */
    public function includesSuperAdmin(array $roleIds): bool
    {
        return AdminTables::query('roles')->whereIn('id', $roleIds)->where('is_superadmin', 1)->exists();
    }

    /**
     * Exam access limits (kmu-cms "Exam Access"): with none, the staff member works on every course
     * of their campus; with some, only on those courses.
     *
     * @param  list<int>  $courseIds
     */
    public function limitToCourses(int $staffId, array $courseIds, ?int $by): void
    {
        DB::transaction(function () use ($staffId, $courseIds, $by): void {
            AdminTables::query('acad_staff_exam_scopes')->where('staff_id', $staffId)->delete();
            foreach (array_unique($courseIds) as $courseId) {
                AdminTables::query('acad_staff_exam_scopes')->insert(['staff_id' => $staffId, 'scope_type' => 'course', 'scope_id' => $courseId, 'created_by' => $by]);
            }
        });
        if (($user = User::query()->where('cms_staff_id', $staffId)->first()) !== null) {
            $this->access->forget($user);
        }
    }
}
