<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * kmu-cms lets a Course ID added by mistake be deleted, but only while nothing uses it. Questions and
 * examinations live here, so kmu-cms asks this view (through its read-only account, see
 * `php artisan cms:audit-reader-sql`) how often a course is used. Counts only, no content.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_course_usage` AS
            SELECT u.course_id, SUM(u.questions) AS questions, SUM(u.examinations) AS examinations
            FROM (
                SELECT course_id, COUNT(*) AS questions, 0 AS examinations FROM qb_question_versions GROUP BY course_id
                UNION ALL
                SELECT course_id, 0, COUNT(*) FROM exm_examinations GROUP BY course_id
            ) u
            GROUP BY u.course_id');
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS `v_cms_course_usage`');
    }
};
