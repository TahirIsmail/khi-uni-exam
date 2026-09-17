<?php

namespace Tests\Concerns;

use App\Domain\Identity\Sso\CmsTicketVerifier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Helpers for tests that need kmu-cms data or sign-on tickets.
 *
 * CMS rows are written to the stand-in CMS database through the default connection, inside the
 * test's RefreshDatabase transaction, so they disappear after each test. The read-only `cms`
 * connection is pointed at the same PDO so the views can see those uncommitted rows.
 */
trait InteractsWithCms
{
    protected function shareCmsConnection(): void
    {
        DB::connection('cms')->setPdo(DB::connection()->getPdo());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function cmsStaff(array $attributes = []): int
    {
        $table = config('database.cms_source_database').'.staff';

        DB::statement("SET SESSION sql_mode = ''");
        $id = DB::table($table)->insertGetId(array_merge([
            'employee_id' => 'EMP-'.bin2hex(random_bytes(4)),
            'name' => 'Ayesha',
            'surname' => 'Khan',
            'email' => 'ayesha.'.bin2hex(random_bytes(3)).'@kmu.test',
            'is_active' => 1,
            'branch_id' => 1,
        ], $attributes));
        DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return (int) $id;
    }

    protected function cmsRole(string $name, bool $superAdmin = false): int
    {
        return (int) DB::table(config('database.cms_source_database').'.roles')->insertGetId([
            'name' => $name,
            'is_active' => 0,
            'is_system' => 0,
            'is_superadmin' => $superAdmin ? 1 : 0,
        ]);
    }

    protected function cmsBranch(string $name = 'Main Campus', string $status = 'active'): int
    {
        return (int) DB::table(config('database.cms_source_database').'.branches')->insertGetId([
            'branch_name' => $name,
            'branch_code' => 'B-'.bin2hex(random_bytes(4)),
            'status' => $status,
        ]);
    }

    protected function cmsGiveBranch(int $staffId, int $branchId): void
    {
        DB::table(config('database.cms_source_database').'.staff_accessible_branches')->insert(['staff_id' => $staffId, 'branch_id' => $branchId]);
    }

    protected function cmsProgramme(int $branchId, string $code = 'MBBS', ?string $name = null): int
    {
        $cms = config('database.cms_source_database');
        DB::statement("SET SESSION sql_mode = ''");
        $id = (int) DB::table("{$cms}.classes")->insertGetId(['branch_id' => $branchId, 'education_type_id' => 1, 'class' => $name ?? $code.' '.bin2hex(random_bytes(2)), 'is_active' => 'no']);
        DB::table("{$cms}.acad_programme_profiles")->insert(['class_id' => $id, 'code' => $code.'-'.bin2hex(random_bytes(2)), 'calendar_type' => 'annual', 'duration_years' => 5]);
        DB::statement("SET SESSION sql_mode = 'ONLY_FULL_GROUP_BY,STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");

        return $id;
    }

    protected function cmsProfessional(int $programmeId, int $sequence = 1): int
    {
        return (int) DB::table(config('database.cms_source_database').'.acad_professionals')->insertGetId([
            'class_id' => $programmeId, 'code' => 'PROF-'.$sequence, 'name' => 'Professional '.$sequence, 'sequence' => $sequence,
        ]);
    }

    protected function cmsCourse(int $programmeId, int $professionalId, string $code = 'FND'): int
    {
        return (int) DB::table(config('database.cms_source_database').'.acad_courses')->insertGetId([
            'course_code' => $code.'-'.bin2hex(random_bytes(3)), 'title' => 'Course '.$code, 'class_id' => $programmeId, 'professional_id' => $professionalId, 'course_kind' => 'module',
        ]);
    }

    /**
     * A staff user holding a new role with the given permissions, working in $branchId, with an
     * "all" scope unless $allScope is false.
     *
     * @param  list<string>  $permissions
     */
    protected function adminWith(array $permissions, int $branchId, bool $allScope = true): User
    {
        $role = $this->cmsRole('Admin '.bin2hex(random_bytes(3)));
        $this->grant($role, ...$permissions);
        $user = $this->staffUser([$role], $branchId);
        if ($allScope) {
            $this->scope($user, 'all');
        }

        return $user;
    }

    protected function cmsAssignRole(int $staffId, int $roleId): void
    {
        DB::table(config('database.cms_source_database').'.staff_roles')->insert(['staff_id' => $staffId, 'role_id' => $roleId, 'is_active' => 1]);
    }

    /**
     * A local user linked to a new CMS staff member holding the given CMS roles, whose own branch is
     * $branchId (none if null).
     *
     * @param  list<int>  $roleIds
     */
    protected function staffUser(array $roleIds = [], ?int $branchId = null): User
    {
        $staffId = $this->cmsStaff(['branch_id' => $branchId]);
        foreach ($roleIds as $roleId) {
            $this->cmsAssignRole($staffId, $roleId);
        }

        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['cms_staff_id' => $staffId])->save();

        return $user;
    }

    protected function grant(int $roleId, string ...$permissions): void
    {
        foreach ($permissions as $permission) {
            DB::table('sec_role_permissions')->insert(['cms_role_id' => $roleId, 'permission_code' => $permission]);
        }
    }

    protected function scope(User $user, string $type, ?int $id = null): void
    {
        DB::table('sec_user_scopes')->insert(['user_id' => $user->id, 'scope_type' => $type, 'scope_id' => $id]);
    }

    /**
     * Builds a ticket exactly as kmu-cms does (application/controllers/admin/Assessment.php there).
     *
     * @param  array<string, mixed>  $claims
     */
    protected function cmsTicket(array $claims, ?string $secret = null): string
    {
        $now = time();
        $payload = CmsTicketVerifier::base64UrlEncode((string) json_encode(array_merge([
            'iss' => 'kmu-cms',
            'aud' => 'kmu-assess',
            'iat' => $now,
            'exp' => $now + 60,
            'jti' => bin2hex(random_bytes(32)),
            'redirect' => '/dashboard',
        ], $claims)));

        $key = base64_decode($secret ?? (string) config('services.kmu_cms.sso_secret'), true);

        return $payload.'.'.CmsTicketVerifier::base64UrlEncode(hash_hmac('sha256', $payload, (string) $key, true));
    }
}
