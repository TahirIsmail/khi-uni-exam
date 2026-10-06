<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Immutability of the question bank, enforced by the database so no code path can get around it
 * (blueprint 8.2, 22.5):
 *
 *  - A version can only be edited while it is a draft or has changes requested. Once submitted,
 *    approved or active, its content is frozen; a change means a new version.
 *  - The same applies to everything that belongs to the version: options, sub-parts, accepted
 *    answers, rubric, references, media links and tags.
 *  - Status changes follow the workflow; anything else is refused.
 *  - Questions are archived, never deleted; only a draft version can be deleted.
 *  - The status log is append-only.
 */
return new class extends Migration
{
    /** Tables that belong to one version and are frozen with it: table => short name for the trigger. */
    private const CHILD_TABLES = [
        'qb_question_items' => 'items',
        'qb_question_options' => 'options',
        'qb_question_answers' => 'answers',
        'qb_question_rubric_criteria' => 'rubric_criteria',
        'qb_references' => 'references',
        'qb_version_media' => 'version_media',
        'qb_version_tags' => 'version_tags',
    ];

    /** Columns that are frozen once the version leaves the drafting stage. */
    private const FROZEN_COLUMNS = [
        'question_id', 'version_no', 'question_type_id', 'branch_id', 'vignette', 'stem', 'lead_in',
        'explanation', 'settings', 'marks', 'negative_marks', 'programme_id', 'professional_id',
        'term_id', 'course_id', 'node_id', 'discipline_id', 'content_hash', 'search_text', 'source',
        'import_row_id', 'author_id',
    ];

    /** from_status => allowed next statuses (blueprint 8.2). */
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
        // Dropping tables leaves stored functions behind, so start from a clean one.
        DB::unprepared('DROP FUNCTION IF EXISTS qb_version_is_editable');
        DB::unprepared("CREATE FUNCTION qb_version_is_editable(p_version_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM qb_question_versions WHERE id = p_version_id;

                RETURN IF(v_status IN ('draft', 'changes_requested'), 1, 0);
            END");

        DB::unprepared("CREATE TRIGGER trg_qb_questions_no_delete BEFORE DELETE ON qb_questions FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Questions are archived, never deleted'");

        $frozen = implode(' OR ', array_map(
            fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)",
            self::FROZEN_COLUMNS,
        ));

        $transitions = '';
        foreach (self::TRANSITIONS as $from => $to) {
            $allowed = $to === [] ? "'\\0'" : "'".implode("', '", $to)."'";
            $transitions .= "WHEN OLD.status = '{$from}' AND NEW.status NOT IN ({$allowed}) THEN 1\n                        ";
        }

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

        DB::unprepared("CREATE TRIGGER trg_qb_versions_delete_drafts_only BEFORE DELETE ON qb_question_versions FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only a draft version can be deleted; archive the question instead';
                END IF;
            END");

        foreach (self::CHILD_TABLES as $table => $short) {
            foreach (['insert', 'update', 'delete'] as $event) {
                $sqlEvent = match ($event) {
                    'insert' => 'INSERT',
                    'update' => 'UPDATE',
                    'delete' => 'DELETE',
                };
                $row = $event === 'insert' ? 'NEW' : 'OLD';

                DB::unprepared("CREATE TRIGGER trg_qb_{$short}_frozen_{$event} BEFORE {$sqlEvent} ON {$table} FOR EACH ROW
                    BEGIN
                        IF qb_version_is_editable({$row}.`version_id`) = 0 THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This version is no longer a draft: create a new version to change it';
                        END IF;
                    END");
            }
        }

        DB::unprepared("CREATE TRIGGER trg_qb_status_log_no_update BEFORE UPDATE ON qb_version_status_log FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The status log is append-only'");
        DB::unprepared("CREATE TRIGGER trg_qb_status_log_no_delete BEFORE DELETE ON qb_version_status_log FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The status log is append-only'");
    }

    public function down(): void
    {
        foreach (['trg_qb_status_log_no_delete', 'trg_qb_status_log_no_update'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        foreach (self::CHILD_TABLES as $short) {
            foreach (['insert', 'update', 'delete'] as $event) {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_qb_{$short}_frozen_{$event}");
            }
        }
        foreach (['trg_qb_versions_delete_drafts_only', 'trg_qb_versions_frozen', 'trg_qb_questions_no_delete'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS qb_version_is_editable');
    }
};
