<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * v_cms_programmes gains is_active: a program switched off in kmu-cms (Program Structure) keeps its
 * questions and examinations, but is not offered when a question is written or imported or an
 * examination is created.
 *
 * Needs kmu-cms migration 20261014_0022_programme_switch_off to have run first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $definitions = CmsViews::definitions((string) config('database.cms_source_database'));

        DB::statement('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_programmes` AS '.$definitions['v_cms_programmes']); // raw-sql-reviewed: view text comes from CmsViews, no user input
    }

    public function down(): void
    {
        $db = '`'.str_replace('`', '``', (string) config('database.cms_source_database')).'`';

        DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_programmes` AS SELECT c.id, c.branch_id, c.education_type_id, c.class AS name, p.code, p.calendar_type, p.structure_type, p.duration_years
            FROM {$db}.classes c JOIN {$db}.acad_programme_profiles p ON p.class_id = c.id"); // raw-sql-reviewed: database name from config
    }
};
