<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Administration of this module moved to kmu-cms (roles and permissions, exam access limits,
 * two-factor setting, audit log screen). Local permission grants, scopes and break-glass accounts
 * are removed; the CMS views gain the permission, limit and settings views; and kmu-cms reads the
 * audit log through v_cms_audit_entries (see `php artisan cms:audit-reader-sql`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('sec_user_scopes');
        Schema::dropIfExists('sec_role_permissions');
        Schema::dropIfExists('sec_permissions');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_break_glass');
        });

        foreach (CmsViews::definitions((string) config('database.cms_source_database')) as $name => $select) {
            DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$name}` AS {$select}");
        }

        DB::statement('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_audit_entries` AS
            SELECT l.id, l.occurred_at, l.actor_type, u.cms_staff_id AS actor_staff_id, u.name AS actor_name, u.email AS actor_email,
                   l.action, l.entity_type, l.entity_id, l.branch_id, l.old_values, l.new_values, l.reason, l.ip, l.request_id
            FROM sec_audit_logs l
            LEFT JOIN users u ON u.id = l.actor_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_cms_audit_entries`');
        foreach (['v_cms_role_permissions', 'v_cms_permission_categories', 'v_cms_staff_exam_scopes', 'v_cms_exam_settings'] as $view) {
            DB::statement("DROP VIEW IF EXISTS `{$view}`");
        }

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_break_glass')->default(false)->after('is_active');
        });
        // The dropped grant and scope tables are not recreated; roll back further to get them back.
    }
};
