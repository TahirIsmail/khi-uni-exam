<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * IQQUIK Phase I categories, step 1C (kmu-cms docs/plan-qbank-categories.md §1.4):
 *
 * - intake_id: the Academic Session a question is filed under (kmu-cms sessions, shown there as the
 *   Intake: "2026 Intake"). Like the rest of where a question is filed, it is fixed once the question
 *   has been sent for review.
 * - node_id becomes optional: a BDS or DPT question may be filed on the course as a whole, with no
 *   topic (KMU: "each Professional/Year directly lists its Courses/Subjects"). MBBS questions still
 *   name a subject of the module; that rule lives in CmsAcademic::placeOf().
 *
 * Blueprint rows and paper items keep node_id NOT NULL: there, 0 stands for "the whole course".
 */
return new class extends Migration
{
    /** The frozen columns of migration 2026_09_24_000101, now with the Academic Session. */
    private const FROZEN_COLUMNS = [
        'question_id', 'version_no', 'question_type_id', 'branch_id', 'vignette', 'stem', 'lead_in',
        'explanation', 'settings', 'marks', 'negative_marks', 'programme_id', 'professional_id',
        'term_id', 'course_id', 'node_id', 'discipline_id', 'exam_type_id', 'intake_id', 'content_hash',
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
            $table->unsignedInteger('node_id')->nullable()->comment('acad_curriculum_nodes.id: the subject or topic; empty = the course as a whole')->change();
            $table->unsignedInteger('intake_id')->nullable()->after('exam_type_id')->comment('kmu-cms sessions.id: the Academic Session (Intake)');
            $table->index(['branch_id', 'intake_id']);
        });

        $this->frozenTrigger(self::FROZEN_COLUMNS);
    }

    public function down(): void
    {
        $this->frozenTrigger(array_values(array_diff(self::FROZEN_COLUMNS, ['intake_id'])));

        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'intake_id']);
            $table->dropColumn('intake_id');
            // node_id stays nullable: questions filed on a whole course may exist by now.
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
