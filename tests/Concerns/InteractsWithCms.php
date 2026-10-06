<?php

namespace Tests\Concerns;

use App\Domain\Identity\Authorization\Permissions;
use App\Models\User;
use App\Support\Cms\CmsTeaching;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

/**
 * Helpers for tests that need admin (kmu-cms style) data, or a signed-in staff member.
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

    protected function cmsCourse(int $programmeId, ?int $professionalId = null, string $code = 'FND'): int
    {
        return (int) DB::table(config('database.cms_source_database').'.acad_courses')->insertGetId([
            'course_code' => $code.'-'.bin2hex(random_bytes(3)), 'title' => 'Course '.$code, 'class_id' => $programmeId, 'professional_id' => $professionalId, 'course_kind' => 'module',
        ]);
    }

    protected function cmsAssignRole(int $staffId, int $roleId): void
    {
        DB::table(config('database.cms_source_database').'.staff_roles')->insert(['staff_id' => $staffId, 'role_id' => $roleId, 'is_active' => 1]);
    }

    /**
     * A local user linked to a new CMS staff member holding the given CMS roles, whose own campus is
     * $branchId (none if null).
     *
     * @param  list<int>  $roleIds
     * @param  array<string, mixed>  $staff  other columns of the staff row, e.g. a name
     */
    protected function staffUser(array $roleIds = [], ?int $branchId = null, array $staff = []): User
    {
        $staffId = $this->cmsStaff(['branch_id' => $branchId, ...$staff]);
        foreach ($roleIds as $roleId) {
            $this->cmsAssignRole($staffId, $roleId);
        }

        $user = User::factory()->create(['password' => null]);
        $user->forceFill(['cms_staff_id' => $staffId])->save();

        return $user;
    }

    /**
     * A topic of a course that questions may be attached to (level template with allow_questions).
     */
    protected function cmsCurriculumNode(int $courseId, int $programmeId, string $name = 'Ischaemic heart disease', bool $allowQuestions = true, ?int $disciplineId = null, ?int $parentId = null): int
    {
        $cms = config('database.cms_source_database');
        // Two levels, as kmu-cms has them: topics take questions, the section above them does not.
        $levelCode = $allowQuestions ? 'topic' : 'section';
        $depth = $allowQuestions ? 2 : 1;
        $levelType = DB::table("{$cms}.acad_level_types")->where('code', $levelCode)->value('id')
            ?? DB::table("{$cms}.acad_level_types")->insertGetId(['code' => $levelCode, 'name' => ucfirst($levelCode)]);

        if (DB::table("{$cms}.acad_level_templates")->where(['class_id' => $programmeId, 'depth' => $depth])->doesntExist()) {
            DB::table("{$cms}.acad_level_templates")->insert([
                'class_id' => $programmeId, 'depth' => $depth, 'level_type_id' => $levelType, 'allow_questions' => (int) $allowQuestions,
            ]);
        }

        $path = '/';
        if ($parentId !== null) {
            $path = DB::table("{$cms}.acad_curriculum_nodes")->where('id', $parentId)->value('path').$parentId.'/';
        }

        return (int) DB::table("{$cms}.acad_curriculum_nodes")->insertGetId([
            'course_id' => $courseId,
            'parent_id' => $parentId,
            'level_type_id' => $levelType,
            'discipline_id' => $disciplineId,
            'code' => 'T-'.bin2hex(random_bytes(3)),
            'name' => $name,
            'path' => $path,
            'depth' => $depth,
            'sort_order' => 1,
            'is_active' => 1,
        ]);
    }

    /**
     * One of the four examination types kmu-cms seeds: annual, supplementary (annual programmes),
     * regular, retake (semester programmes). The test programmes run on the annual calendar.
     */
    protected function cmsExamType(string $code = 'annual'): int
    {
        $table = config('database.cms_source_database').'.acad_exam_types';
        $types = [
            'annual' => ['Annual', 'annual', 0, 1],
            'supplementary' => ['Supplementary', 'annual', 1, 2],
            'regular' => ['Regular', 'semester', 0, 3],
            'retake' => ['Retake', 'semester', 1, 4],
        ];

        $id = DB::table($table)->where('code', $code)->value('id');
        if ($id !== null) {
            return (int) $id;
        }

        [$name, $calendar, $resit, $sort] = $types[$code];

        return (int) DB::table($table)->insertGetId([
            'code' => $code, 'name' => $name, 'calendar_type' => $calendar, 'is_resit' => $resit, 'sort_order' => $sort, 'is_active' => 1,
        ]);
    }

    protected function cmsDiscipline(string $name = 'Physiology'): int
    {
        return (int) DB::table(config('database.cms_source_database').'.acad_disciplines')->insertGetId([
            'code' => 'D-'.bin2hex(random_bytes(3)), 'name' => $name, 'is_active' => 1,
        ]);
    }

    /**
     * The "Question Bank & Exams" permission group and categories, as kmu-cms migration 0006 creates them.
     */
    protected function cmsPermissionCatalogue(): void
    {
        $cms = config('database.cms_source_database');
        if (DB::table("{$cms}.permission_group")->where('short_code', 'qbank_exams')->exists()) {
            return;
        }

        $group = DB::table("{$cms}.permission_group")->insertGetId(['name' => 'Question Bank & Exams', 'short_code' => 'qbank_exams', 'is_active' => 1, 'system' => 0]);
        foreach (Permissions::cmsCheckboxes() as $category => $checkboxes) {
            DB::table("{$cms}.permission_category")->insert([
                'perm_group_id' => $group,
                'name' => $category,
                'short_code' => $category,
                'enable_view' => (int) in_array('view', $checkboxes, true),
                'enable_add' => (int) in_array('add', $checkboxes, true),
                'enable_edit' => (int) in_array('edit', $checkboxes, true),
                'enable_delete' => (int) in_array('delete', $checkboxes, true),
            ]);
        }
    }

    /**
     * Ticks checkboxes for a role in Roles → Assign Permission, e.g. cmsGrant($role, 'qbank_questions', 'view', 'add').
     */
    protected function cmsGrant(int $roleId, string $category, string ...$checkboxes): void
    {
        $this->cmsPermissionCatalogue();
        $cms = config('database.cms_source_database');
        $categoryId = DB::table("{$cms}.permission_category")->where('short_code', $category)->value('id');

        DB::table("{$cms}.roles_permissions")->insert([
            'role_id' => $roleId,
            'perm_cat_id' => $categoryId,
            'can_view' => (int) in_array('view', $checkboxes, true),
            'can_add' => (int) in_array('add', $checkboxes, true),
            'can_edit' => (int) in_array('edit', $checkboxes, true),
            'can_delete' => (int) in_array('delete', $checkboxes, true),
        ]);
    }

    /** An exam access limit set in kmu-cms (Question Bank & Exams → Exam Access). */
    protected function cmsExamScope(User $user, string $type, int $id): void
    {
        DB::table(config('database.cms_source_database').'.acad_staff_exam_scopes')->insert([
            'staff_id' => $user->cms_staff_id, 'scope_type' => $type, 'scope_id' => $id,
        ]);
    }

    /**
     * A teaching assignment made in kmu-cms (Academics → Assign Program Teacher): this staff member
     * teaches this programme in this intake, which is what puts its examinations on their Marking
     * screen.
     */
    protected function cmsTeaches(User $user, int $programmeId, int $intakeId, int $sectionId = 1): void
    {
        DB::table(config('database.cms_source_database').'.class_teacher')->insert([
            'class_id' => $programmeId,
            'staff_id' => $user->cms_staff_id,
            'section_id' => $sectionId,
            'session_id' => $intakeId,
        ]);

        app(CmsTeaching::class)->forget();
    }

    /** Turns two-factor authentication on or off in kmu-cms (Exam Module Settings). */
    protected function cmsMfa(bool $enabled): void
    {
        $this->cmsExamSettings(['kmu_assess_mfa_enabled' => (int) $enabled]);
    }

    /**
     * The exam module settings a Super Admin keeps in kmu-cms: how many reviews a question needs,
     * how long a reviewer has, whether approval puts it into use, reviewer anonymity.
     *
     * @param  array<string, int>  $settings
     */
    protected function cmsExamSettings(array $settings = []): void
    {
        $table = config('database.cms_source_database').'.sch_settings';
        DB::table($table)->delete();
        DB::table($table)->insert(array_merge([
            'id' => 1,
            'name' => 'KMU',
            'kmu_assess_mfa_enabled' => 0,
            'kmu_assess_reviews_required' => 1,
            'kmu_assess_review_days' => 7,
            'kmu_assess_auto_activate' => 1,
            'kmu_assess_reviewer_anonymous' => 0,
            // Off here so tests go through the approving authority; the shortcut has its own tests.
            'kmu_assess_reviewer_accept_stores' => 0,
            'kmu_assess_academic_review' => 1,
        ], $settings));
    }

    /**
     * Gives the user a password and signs them in on the sign-in page, as staff do here.
     */
    protected function signIn(User $user, string $password = 'correct-horse-9'): TestResponse
    {
        $user->forceFill(['password' => Hash::make($password)])->save();

        return $this->post('/login', ['email' => $user->email, 'password' => $password]);
    }
}
