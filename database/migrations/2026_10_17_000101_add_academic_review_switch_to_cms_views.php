<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * v_cms_exam_settings gains kmu_assess_academic_review: when off, the department / subject review is
 * the only level of review (no QBank / academic reviewer is asked, and its Accept can store the
 * question).
 *
 * Needs kmu-cms migration 20261015_0023_academic_review_switch to have run first.
 */
return new class extends Migration
{
    public function up(): void
    {
        $definitions = CmsViews::definitions((string) config('database.cms_source_database'));

        DB::statement('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_exam_settings` AS '.$definitions['v_cms_exam_settings']); // raw-sql-reviewed: view text comes from CmsViews, no user input
    }

    public function down(): void
    {
        $db = '`'.str_replace('`', '``', (string) config('database.cms_source_database')).'`';

        DB::statement("CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_exam_settings` AS SELECT s.kmu_assess_mfa_enabled, s.kmu_assess_reviews_required, s.kmu_assess_review_days,
                       s.kmu_assess_auto_activate, s.kmu_assess_reviewer_anonymous, s.kmu_assess_reviewer_accept_stores
                FROM {$db}.sch_settings s ORDER BY s.id LIMIT 1"); // raw-sql-reviewed: database name from config
    }
};
