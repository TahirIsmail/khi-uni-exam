<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Approving each new computer at a centre becomes a switch under Setup, off unless turned on: the
 * admin database's sch_settings gains the column (when an older install lacks it) and the settings
 * view reads it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $admin = (string) config('database.cms_source_database');
        $exists = DB::table('information_schema.columns')
            ->where('table_schema', $admin)->where('table_name', 'sch_settings')->where('column_name', 'kmu_assess_device_approval')->exists();
        if (! $exists) {
            DB::statement('ALTER TABLE `'.str_replace('`', '``', $admin).'`.`sch_settings` ADD COLUMN `kmu_assess_device_approval` TINYINT(1) NOT NULL DEFAULT 0'); // raw-sql-reviewed: database name from config
        }

        $definitions = CmsViews::definitions($admin);
        DB::statement('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_exam_settings` AS '.$definitions['v_cms_exam_settings']); // raw-sql-reviewed: view text comes from CmsViews, no user input
    }

    public function down(): void
    {
        // The column is left in place; the earlier view text is restored by the earlier migrations.
    }
};
