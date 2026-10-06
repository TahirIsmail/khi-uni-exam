<?php

namespace App\Console\Commands;

use App\Domain\Identity\Authorization\Permissions;
use App\Support\Admin\StaffAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sets the app up on a new server, where there is no kmu-cms: creates the admin database's tables
 * (database/admin/schema.sql), runs the migrations (whose v_cms_* views read those tables), fills
 * in the fixed lists — permission checkboxes, level types, exam types — and the university's
 * campus, settings and Super Admin role, and creates the first Super Admin login.
 *
 * Safe to run again: anything already there is left as it is.
 */
class InstallExam extends Command
{
    protected $signature = 'exam:install
        {--university= : The university name (the campus everything belongs to)}
        {--email= : Email of the first Super Admin}
        {--name= : Name of the first Super Admin}
        {--password= : Password of the first Super Admin (asked for if left out)}';

    protected $description = 'Create the admin database, run the migrations and create the first Super Admin';

    /**
     * The "Question Bank & Exams" checkboxes on the role screen: name, short code, and which of
     * View / Add / Edit / Delete it offers — the same list kmu-cms has.
     */
    public const CATEGORIES = [
        ['Questions', 'qbank_questions', 1, 1, 1, 1],
        ['Export Questions & Answer Keys', 'qbank_questions_export', 1, 0, 0, 0],
        ['Advanced Question Filters', 'qbank_filters', 1, 0, 0, 0],
        ['Review Questions (Department / Subject)', 'qbank_review', 1, 0, 0, 0],
        ['Review Questions (QBank / Academic)', 'qbank_review_academic', 1, 0, 0, 0],
        ['Assign Reviewers', 'qbank_review_assign', 1, 0, 0, 0],
        ['Pre-hoc Assessment', 'qbank_prehoc', 1, 0, 0, 0],
        ['Approve Questions', 'qbank_approve', 1, 0, 0, 0],
        ['Import Questions', 'qbank_import', 1, 1, 0, 0],
        ['Blueprints', 'exam_blueprints', 1, 1, 1, 0],
        ['Approve Blueprints', 'exam_blueprints_approve', 1, 0, 0, 0],
        ['Examinations & Papers', 'exam_papers', 1, 1, 1, 0],
        ['Approve Papers', 'exam_papers_approve', 1, 0, 0, 0],
        ['Finalise Papers', 'exam_papers_finalise', 1, 0, 0, 0],
        ['Publish Papers', 'exam_papers_publish', 1, 0, 0, 0],
        ['New Version of a Finalised Paper', 'exam_papers_unlock', 1, 0, 0, 0],
        ['Candidates', 'exam_candidates', 1, 1, 1, 1],
        ['Check In Candidates', 'exam_checkin', 1, 0, 0, 0],
        ['Grant Extra Time', 'exam_extra_time', 1, 0, 0, 0],
        ['Centres, Rooms & Devices', 'exam_centres', 1, 1, 1, 1],
        ['Allocate Candidates & Staff', 'exam_allocation', 1, 0, 0, 0],
        ['Monitor Live Exams', 'exam_monitor', 1, 0, 0, 0],
        ['Pause / Unlock / End Exam Sessions', 'exam_session_control', 1, 0, 0, 0],
        ['Proctoring Events', 'proctor_events', 1, 0, 0, 0],
        ['Proctoring Evidence', 'proctor_evidence', 1, 0, 0, 0],
        ['Export Proctoring Evidence', 'proctor_evidence_export', 1, 0, 0, 0],
        ['Decide Proctoring Cases', 'proctor_decisions', 1, 0, 0, 0],
        ['Assign Examiners', 'exam_marking_assign', 1, 0, 0, 0],
        ['Mark Manually-Marked Items', 'exam_marking', 1, 0, 0, 0],
        ['Adjudicate Marking Disagreements', 'exam_marking_adjudicate', 1, 0, 0, 0],
        ['Results', 'exam_results', 1, 0, 0, 0],
        ['Re-key / Rescore Results', 'exam_results_rescore', 1, 0, 0, 0],
        ['Approve Results', 'exam_results_approve', 1, 0, 0, 0],
        ['Publish Results', 'exam_results_publish', 1, 0, 0, 0],
        ['Enter Practical & Internal Marks', 'exam_result_components', 1, 0, 0, 0],
        ['Item Analysis', 'exam_item_analysis', 1, 1, 1, 0],
        ['Item Analysis Thresholds', 'exam_item_analysis_thresholds', 1, 0, 0, 0],
        ['Exam Reports', 'exam_reports', 1, 0, 0, 0],
        ['Export Exam Reports', 'exam_reports_export', 1, 0, 0, 0],
    ];

    public function handle(StaffAccounts $accounts): int
    {
        $admin = DB::connection('admin');

        if (! Schema::connection('admin')->hasTable('staff')) {
            // A fixed file shipped with the app, not input: run it as one script.
            $admin->getPdo()->exec((string) file_get_contents(database_path('admin/schema.sql')));
            $this->info('Admin database tables created.');
        }

        $this->call('migrate', ['--force' => true]);

        $this->seedPermissionCheckboxes();

        if ($admin->table('sch_settings')->doesntExist()) {
            $admin->table('sch_settings')->insert(['name' => $this->university()]);
        }
        if ($admin->table('branches')->doesntExist()) {
            $admin->table('branches')->insert(['branch_name' => $this->university(), 'branch_code' => 'MAIN', 'status' => 'active']);
        }
        $superAdminRole = $admin->table('roles')->where('is_superadmin', 1)->value('id')
            ?? $admin->table('roles')->insertGetId(['name' => 'Super Admin', 'slug' => 'super-admin', 'is_active' => 1, 'is_system' => 1, 'is_superadmin' => 1]);
        if ($admin->table('acad_exam_types')->where('code', 'entry')->doesntExist()) {
            $admin->table('acad_exam_types')->insert(['code' => 'entry', 'name' => 'Entry Test', 'calendar_type' => 'any', 'is_resit' => 0, 'sort_order' => 0, 'is_active' => 1]);
        }

        $hasSuperAdmin = $admin->table('staff_roles')->where('role_id', $superAdminRole)->exists();
        if (! $hasSuperAdmin || $this->option('email')) {
            $email = $this->option('email') ?: $this->ask('Email of the first Super Admin');
            $name = $this->option('name') ?: $this->ask('Their name', 'Administrator');
            $password = $this->option('password') ?: $this->secret('Their password (at least 8 characters)');
            if (strlen((string) $password) < 8) {
                $this->error('The password must be at least 8 characters.');

                return self::FAILURE;
            }

            $branchId = (int) $admin->table('branches')->orderBy('id')->value('id');
            $accounts->save(null, [
                'name' => (string) $name,
                'surname' => '',
                'email' => (string) $email,
                'branch_id' => $branchId,
                'is_active' => true,
                'role_ids' => [(int) $superAdminRole],
            ], (string) $password);
            $this->info("Super Admin {$email} can now sign in.");
        }

        $this->info('Done. Sign in at '.route('login').'; set up programmes, courses and staff under Setup.');

        return self::SUCCESS;
    }

    private function university(): string
    {
        return (string) ($this->option('university') ?: 'University');
    }

    private function seedPermissionCheckboxes(): void
    {
        $admin = DB::connection('admin');
        $group = $admin->table('permission_group')->where('short_code', 'qbank_exams')->value('id')
            ?? $admin->table('permission_group')->insertGetId(['name' => 'Question Bank & Exams', 'short_code' => 'qbank_exams', 'is_active' => 1, 'system' => 0]);

        foreach (self::CATEGORIES as [$name, $code, $view, $add, $edit, $delete]) {
            $admin->table('permission_category')->updateOrInsert(
                ['short_code' => $code],
                ['perm_group_id' => $group, 'name' => $name, 'enable_view' => $view, 'enable_add' => $add, 'enable_edit' => $edit, 'enable_delete' => $delete],
            );
        }

        // Every checkbox the permission catalogue relies on must be on the role screen.
        foreach (Permissions::cmsCheckboxes() as $code => $boxes) {
            foreach ($boxes as $box) {
                $admin->table('permission_category')->where('short_code', $code)->update(['enable_'.$box => 1]);
            }
        }
    }
}
