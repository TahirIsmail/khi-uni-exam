<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * IQQUIK Phase I categories (kmu-cms docs/plan-qbank-categories.md §1.4):
 *
 * - v_cms_programmes gains structure_type: modular (MBBS: module > subject) or subject (BDS, DPT).
 * - v_cms_professional_terms names a semester the way KMU does, straight through the programme
 *   (Second Professional's terms are Semester III and IV, not I and II again), with the number in
 *   semester_no. The campus semester row it links to moves to section_name.
 *
 * Needs kmu-cms migration 20261010_0018_programme_structure_type to have run first, since the
 * column this projects does not exist before it.
 */
return new class extends Migration
{
    private const CHANGED = ['v_cms_programmes', 'v_cms_professional_terms'];

    public function up(): void
    {
        $definitions = CmsViews::definitions((string) config('database.cms_source_database'));

        foreach (self::CHANGED as $name) {
            DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$name}` AS {$definitions[$name]}"); // raw-sql-reviewed: view text comes from CmsViews, no user input
        }
    }

    public function down(): void
    {
        $db = '`'.str_replace('`', '``', (string) config('database.cms_source_database')).'`';

        // The definitions as they were before this migration.
        DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_programmes` AS SELECT c.id, c.branch_id, c.education_type_id, c.class AS name, p.code, p.calendar_type, p.duration_years
            FROM {$db}.classes c JOIN {$db}.acad_programme_profiles p ON p.class_id = c.id"); // raw-sql-reviewed: database name from config
        DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_professional_terms` AS SELECT t.id, t.professional_id, t.section_id, s.section AS name, t.sequence, t.is_active
            FROM {$db}.acad_professional_terms t JOIN {$db}.sections s ON s.id = t.section_id"); // raw-sql-reviewed: database name from config
    }
};
