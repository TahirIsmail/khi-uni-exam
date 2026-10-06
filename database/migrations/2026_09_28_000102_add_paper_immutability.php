<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A paper that is no longer a draft is frozen — by the database, so no code path can get around it,
 * exactly as a submitted question version and a submitted blueprint are:
 *
 *  - Its items (exm_paper_items) and its own settings (exm_papers: shuffle, status apart) can only be
 *    written while it is a draft.
 *  - Its status moves Draft -> Submitted -> Approved -> Finalised -> Published, and Submitted or
 *    Approved back to Draft ("sent back"); nothing else, and never once Finalised.
 *  - A comment can only be added or resolved while the paper is Submitted or Approved: moderation is
 *    over once it is finalised.
 *  - A paper is never deleted once it has left draft.
 */
return new class extends Migration
{
    /** Columns of exm_papers frozen once it leaves draft (status and its own workflow columns apart). */
    private const PAPER_FROZEN = ['examination_id', 'version_no', 'shuffle_questions', 'shuffle_options'];

    public function up(): void
    {
        DB::unprepared('DROP FUNCTION IF EXISTS exm_paper_is_draft');
        DB::unprepared("CREATE FUNCTION exm_paper_is_draft(p_paper_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM exm_papers WHERE id = p_paper_id;

                RETURN IF(v_status IS NULL OR v_status = 'draft', 1, 0);
            END");

        DB::unprepared('DROP FUNCTION IF EXISTS exm_paper_moderating');
        DB::unprepared("CREATE FUNCTION exm_paper_moderating(p_paper_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM exm_papers WHERE id = p_paper_id;

                RETURN IF(v_status IN ('submitted', 'approved'), 1, 0);
            END");

        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $row = $event === 'INSERT' ? 'NEW' : 'OLD';

            DB::unprepared('CREATE TRIGGER trg_exm_paper_items_frozen_'.strtolower($event)." BEFORE {$event} ON exm_paper_items FOR EACH ROW
                BEGIN
                    IF exm_paper_is_draft({$row}.`paper_id`) = 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This paper is no longer a draft: its items cannot be changed';
                    END IF;
                END");
        }

        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $row = $event === 'INSERT' ? 'NEW' : 'OLD';

            DB::unprepared('CREATE TRIGGER trg_exm_paper_comments_moderating_'.strtolower($event)." BEFORE {$event} ON exm_paper_comments FOR EACH ROW
                BEGIN
                    IF exm_paper_moderating({$row}.`paper_id`) = 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Comments can only be added or changed while the paper is being moderated';
                    END IF;
                END");
        }

        $frozen = implode(' OR ', array_map(
            fn (string $column): string => "NOT (NEW.`{$column}` <=> OLD.`{$column}`)",
            self::PAPER_FROZEN,
        ));

        DB::unprepared("CREATE TRIGGER trg_exm_papers_frozen BEFORE UPDATE ON exm_papers FOR EACH ROW
            BEGIN
                IF exm_paper_is_draft(OLD.id) = 0 AND ({$frozen}) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This paper is no longer a draft: it cannot be moved to another examination or version, or change how it is presented';
                END IF;

                IF NOT (NEW.status <=> OLD.status) THEN
                    IF (CASE
                        WHEN OLD.status = 'draft' AND NEW.status NOT IN ('submitted') THEN 1
                        WHEN OLD.status = 'submitted' AND NEW.status NOT IN ('draft', 'approved') THEN 1
                        WHEN OLD.status = 'approved' AND NEW.status NOT IN ('draft', 'finalised') THEN 1
                        WHEN OLD.status = 'finalised' AND NEW.status NOT IN ('published') THEN 1
                        WHEN OLD.status = 'published' THEN 1
                        ELSE 0
                    END) = 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That is not an allowed status change for a paper';
                    END IF;
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER trg_exm_papers_no_delete BEFORE DELETE ON exm_papers FOR EACH ROW
            BEGIN
                IF OLD.status <> 'draft' THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A paper that has left draft is never deleted';
                END IF;
            END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_exm_papers_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_exm_papers_frozen');
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_exm_paper_comments_moderating_{$event}");
            DB::unprepared("DROP TRIGGER IF EXISTS trg_exm_paper_items_frozen_{$event}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS exm_paper_moderating');
        DB::unprepared('DROP FUNCTION IF EXISTS exm_paper_is_draft');
    }
};
