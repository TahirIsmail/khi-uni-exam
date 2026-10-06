<?php

namespace App\Support\Admin;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Roles and their "Question Bank & Exams" checkboxes, in the same tables as kmu-cms Roles → Assign
 * Permission (roles, roles_permissions, permission_category). A Super Admin role needs no boxes.
 */
final class RoleGrants
{
    public const BOXES = ['view', 'add', 'edit', 'delete'];

    /** @return array{categories: array<int, array<string, mixed>>, roles: array<int, array<string, mixed>>} */
    public function overview(): array
    {
        $grants = AdminTables::query('roles_permissions')->get()->groupBy('role_id');
        $staffCounts = AdminTables::query('staff_roles')->selectRaw('role_id, COUNT(*) AS n')->groupBy('role_id')->pluck('n', 'role_id'); // raw-sql-reviewed: constant aggregate, no input

        return [
            'categories' => $this->categories()->map(fn ($c) => [
                'id' => (int) $c->id,
                'name' => $c->name,
                'boxes' => array_values(array_filter(self::BOXES, fn ($box) => (int) $c->{'enable_'.$box} === 1)),
            ])->values()->all(),
            'roles' => AdminTables::query('roles')->orderByDesc('is_superadmin')->orderBy('name')->get()->map(fn ($r) => [
                'id' => (int) $r->id,
                'name' => $r->name,
                'isSuperAdmin' => (int) $r->is_superadmin === 1,
                'staffCount' => (int) ($staffCounts[$r->id] ?? 0),
                'grants' => ($grants[$r->id] ?? collect())->mapWithKeys(fn ($g) => [
                    (int) $g->perm_cat_id => array_values(array_filter(self::BOXES, fn ($box) => (int) $g->{'can_'.$box} === 1)),
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    public function create(string $name): int
    {
        return (int) AdminTables::query('roles')->insertGetId(['name' => $name, 'slug' => str($name)->slug()->toString(), 'is_active' => 1]);
    }

    /**
     * Renames the role and replaces its ticked boxes. Boxes a category does not offer are ignored.
     *
     * @param  array<int|string, list<string>>  $grants  category id => ticked boxes
     */
    public function update(int $role, string $name, array $grants): void
    {
        $row = AdminTables::query('roles')->where('id', $role)->first();
        abort_if($row === null, 404);
        $categories = $this->categories()->keyBy('id');

        DB::transaction(function () use ($role, $row, $name, $grants, $categories): void {
            AdminTables::query('roles')->where('id', $role)->update(['name' => $name]);
            if ((int) $row->is_superadmin === 1) {
                return;
            }
            AdminTables::query('roles_permissions')->where('role_id', $role)->delete();
            foreach ($grants as $categoryId => $boxes) {
                $category = $categories->get((int) $categoryId);
                if ($category === null || $boxes === []) {
                    continue;
                }
                $grant = ['role_id' => $role, 'perm_cat_id' => (int) $categoryId];
                foreach (self::BOXES as $box) {
                    $grant['can_'.$box] = in_array($box, $boxes, true) && (int) $category->{'enable_'.$box} === 1 ? 1 : 0;
                }
                AdminTables::query('roles_permissions')->insert($grant);
            }
        });
    }

    /** Deletes a role nobody has; the Super Admin role is never deleted. Returns false if it was kept. */
    public function delete(int $role): bool
    {
        $row = AdminTables::query('roles')->where('id', $role)->first();
        abort_if($row === null, 404);
        if ((int) $row->is_superadmin === 1 || AdminTables::query('staff_roles')->where('role_id', $role)->exists()) {
            return false;
        }
        DB::transaction(function () use ($role): void {
            AdminTables::query('roles_permissions')->where('role_id', $role)->delete();
            AdminTables::query('roles')->where('id', $role)->delete();
        });

        return true;
    }

    /** @return Collection<int, stdClass> */
    private function categories(): Collection
    {
        return AdminTables::query('permission_category as c')
            ->join(AdminTables::name('permission_group as g'), 'g.id', '=', 'c.perm_group_id')
            ->where('g.short_code', 'qbank_exams')
            ->orderBy('c.id')
            ->get(['c.id', 'c.name', 'c.enable_view', 'c.enable_add', 'c.enable_edit', 'c.enable_delete']);
    }
}
