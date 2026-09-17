<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Branch (campus) access: adds v_cms_staff_branches and a branch_id column on the professional and
 * course views, so every permission check can be limited to the branches a user may work in.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (CmsViews::definitions((string) config('database.cms_source_database')) as $name => $select) {
            DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$name}` AS {$select}");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_cms_staff_branches`');
    }
};
