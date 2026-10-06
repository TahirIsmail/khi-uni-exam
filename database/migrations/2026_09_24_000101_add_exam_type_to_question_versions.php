<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The examination type a question is written for — Annual, Supplementary, Regular or Retake — is
 * part of where the question is filed (KMU QBank structure: Programme → Year/Semester →
 * Examination → Module/Subject → Topic → Question). It is saved with the question, searched on,
 * and, like the rest of its place, fixed once the question has been sent for review.
 *
 * The types themselves live in kmu-cms (acad_exam_types) and depend on the programme's calendar:
 * Annual and Supplementary for annual programmes, Regular and Retake for semester programmes.
 */
return new class extends Migration
{
    /** The frozen columns of migration 2026_09_19_000103, now with the examination type. */
    private const FROZEN_COLUMNS = [
        'question_id', 'version_no', 'question_type_id', 'branch_id', 'vignette', 'stem', 'lead_in',
        'explanation', 'settings', 'marks', 'negative_marks', 'programme_id', 'professional_id',
        'term_id', 'course_id', 'node_id', 'discipline_id', 'exam_type_id', 'content_hash',
        'search_text', 'source', 'import_row_id', 'author_id',
    ];

    /** Unchanged from migration 2026_09_19_000103 (blueprint 8.2). */
    private const TRANSITIONS = [
        'draft' => ['submitted', 'archived'],
        'submitted' => ['under_review', 'changes_requested', 'archived'],
        'under_review' => ['changes_requested', 'approved', 'archived'],
        'changes_requested' => ['submitted', 'archived'],
        'approved' => ['active', 'archived'],
        'active' => ['on_hold', 'superseded', 'retired', 'archived'],
        'on_hold' => ['active', 'retired', 'archived'],
        'superseded' => [],
        'retired' => [],
        'archived' => [],
    ];

    public function up(): void
    {
        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->unsignedInteger('exam_type_id')->nullable()->after('node_id')->comment('kmu-cms acad_exam_types: Annual, Supplementary, Regular, Retake');
            $table->index(['branch_id', 'exam_type_id']);
        });

        $this->frozenTrigger(self::FROZEN_COLUMNS);
    }

    public function down(): void
    {
        $this->frozenTrigger(array_values(array_diff(self::FROZEN_COLUMNS, ['exam_type_id'])));

        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'exam_type_id']);
            $table->dropColumn('exam_type_id');
        });
    }

    /**
     * @param  list<literal-string>  $columns
     */
    private function frozenTrigger(array $columns): void
    {
        $frozen = implode(' OR ', array_map(
            fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)",
            $columns,
        ));

        $transitions = '';
        foreach (self::TRANSITIONS as $from => $to) {
            $allowed = $to === [] ? "'\\0'" : "'".implode("', '", $to)."'";
            $transitions .= "WHEN OLD.status = '{$from}' AND NEW.status NOT IN ({$allowed}) THEN 1\n                        ";
        }

        DB::unprepared('DROP TRIGGER IF EXISTS trg_qb_versions_frozen');
        DB::unprepared("CREATE TRIGGER trg_qb_versions_frozen BEFORE UPDATE ON qb_question_versions FOR EACH ROW
            BEGIN
                IF OLD.status NOT IN ('draft', 'changes_requested') AND ({$frozen}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This version is no longer a draft: its content cannot be changed, create a new version';
                END IF;

                IF NOT (NEW.status <=> OLD.status) THEN
                    IF (CASE
                        {$transitions}ELSE 0
                    END) = 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That is not an allowed status change for a question version';
                    END IF;
                END IF;
            END");
    }
};
