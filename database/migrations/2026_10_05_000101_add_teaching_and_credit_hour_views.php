<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Two additions to the read-only views over kmu-cms: v_cms_teaching_assignments (who teaches which
 * programme in which intake — CMS Academics → Assign Program Teacher), and credit hours on
 * v_cms_courses, which a semester GPA cannot be worked out without.
 *
 * Needs kmu-cms migration 20261005_0013_course_credit_hours to have run first, since the column
 * this projects does not exist before it.
 */
return new class extends Migration
{
    private const ADDED = ['v_cms_teaching_assignments', 'v_cms_courses'];

    public function up(): void
    {
        $definitions = CmsViews::definitions((string) config('database.cms_source_database'));

        foreach (self::ADDED as $name) {
            DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `{$name}` AS {$definitions[$name]}");
        }
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_cms_teaching_assignments`');

        // v_cms_courses itself stays — it existed before this migration; only its credit_hours
        // column is new, and a view cannot keep half of its own definition.
    }
};
