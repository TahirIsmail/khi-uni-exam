<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A blueprint that has been submitted or approved is not changed by anyone, by any route, including
 * a direct UPDATE in SQL — the same promise the question bank makes for a submitted version:
 *
 *  - Its rows, targets and the examination's sections can only be written while it is a draft.
 *  - The examination's place in the academic structure and its total marks are fixed with it: they
 *    are what the blueprint was checked against.
 *  - A blueprint moves draft → submitted → approved, and back to draft (returned or reopened); nothing
 *    else. It cannot be approved without being submitted first.
 *  - Nothing past the blueprint stage is deleted.
 *
 * Changing an approved blueprint means returning it to draft, which needs the approving right and is
 * written to the audit log with the reason.
 */
return new class extends Migration
{
    private const EXAMINATION_FROZEN = ['public_ref', 'branch_id', 'programme_id', 'professional_id', 'term_id', 'course_id', 'exam_type_id', 'total_marks'];

    /** Tables holding the content of a blueprint: table => short name for the trigger. */
    private const BLUEPRINT_CHILDREN = [
        'exm_blueprint_rows' => 'rows',
        'exm_blueprint_targets' => 'targets',
    ];

    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS exm_blueprint_is_draft');
        DB::unprepared("CREATE FUNCTION exm_blueprint_is_draft(p_blueprint_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM exm_blueprints WHERE id = p_blueprint_id;

                RETURN IF(v_status IS NULL OR v_status = 'draft', 1, 0);
            END");

        DB::unprepared('DROP FUNCTION IF EXISTS exm_examination_is_draft');
        DB::unprepared("CREATE FUNCTION exm_examination_is_draft(p_examination_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM exm_blueprints WHERE examination_id = p_examination_id;

                RETURN IF(v_status IS NULL OR v_status = 'draft', 1, 0);
            END");

        foreach (self::BLUEPRINT_CHILDREN as $table => $short) {
            foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
                $row = $event === 'INSERT' ? 'NEW' : 'OLD';

                DB::unprepared("CREATE TRIGGER trg_exm_{$short}_frozen_".strtolower($event)." BEFORE {$event} ON {$table} FOR EACH ROW
                    BEGIN
                        IF exm_blueprint_is_draft({$row}.`blueprint_id`) = 0 THEN
                            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This blueprint has been submitted or approved: return it to draft to change it';
                        END IF;
                    END");
            }
        }

        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $row = $event === 'INSERT' ? 'NEW' : 'OLD';

            DB::unprepared('CREATE TRIGGER trg_exm_sections_frozen_'.strtolower($event)." BEFORE {$event} ON exm_sections FOR EACH ROW
                BEGIN
                    IF exm_examination_is_draft({$row}.`examination_id`) = 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This blueprint has been submitted or approved: return it to draft to change the sections';
                    END IF;
                END");
        }

        $frozen = implode(' OR ', array_map(
            fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)",
            self::EXAMINATION_FROZEN,
        ));

        DB::unprepared("CREATE TRIGGER trg_exm_examinations_frozen BEFORE UPDATE ON exm_examinations FOR EACH ROW
            BEGIN
                IF exm_examination_is_draft(OLD.id) = 0 AND ({$frozen}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The blueprint of this examination has been submitted or approved: its course, examination type and total marks cannot be changed';
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER trg_exm_examinations_no_delete BEFORE DELETE ON exm_examinations FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' OR exm_examination_is_draft(OLD.id) = 0 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An examination past the blueprint stage is never deleted';
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER trg_exm_blueprints_workflow BEFORE UPDATE ON exm_blueprints FOR EACH ROW
            BEGIN
                IF NOT (NEW.examination_id <=> OLD.examination_id) OR NOT (NEW.branch_id <=> OLD.branch_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A blueprint cannot be moved to another examination or campus';
                END IF;

                IF NOT (NEW.status <=> OLD.status) THEN
                    IF (CASE
                        WHEN OLD.status = 'draft' AND NEW.status NOT IN ('submitted') THEN 1
                        WHEN OLD.status = 'submitted' AND NEW.status NOT IN ('draft', 'approved') THEN 1
                        WHEN OLD.status = 'approved' AND NEW.status NOT IN ('draft') THEN 1
                        ELSE 0
                    END) = 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That is not an allowed status change for a blueprint';
                    END IF;
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER trg_exm_blueprints_no_delete BEFORE DELETE ON exm_blueprints FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A submitted or approved blueprint is never deleted';
                END IF;
            END");
    }

    public function down(): void
    {
        foreach (['trg_exm_blueprints_no_delete', 'trg_exm_blueprints_workflow', 'trg_exm_examinations_no_delete', 'trg_exm_examinations_frozen'] as $trigger) {
            DB::unprepared("DROP TRIGGER IF EXISTS {$trigger}");
        }
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_exm_sections_frozen_{$event}");
            foreach (self::BLUEPRINT_CHILDREN as $short) {
                DB::unprepared("DROP TRIGGER IF EXISTS trg_exm_{$short}_frozen_{$event}");
            }
        }
        DB::unprepared('DROP FUNCTION IF EXISTS exm_examination_is_draft');
        DB::unprepared('DROP FUNCTION IF EXISTS exm_blueprint_is_draft');
    }
};
