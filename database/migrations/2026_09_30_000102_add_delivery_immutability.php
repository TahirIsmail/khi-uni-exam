<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * What delivery freezes, and where — the database enforces it too, so a direct UPDATE cannot bypass
 * the application any more than it can for the question bank, the blueprint or the paper:
 *
 *  - An answer event, once written, is never changed or removed: the record of what a candidate did
 *    and when is exactly that, a record.
 *  - A candidate's paper — which items, in what order, with which option order — is fixed the moment
 *    the attempt is anything but not_started.
 *  - An attempt moves not_started -> in_progress -> (paused, back to in_progress) -> submitted, and no
 *    other way; nobody's identity, examination or paper on the attempt ever changes; it is never
 *    deleted.
 *  - A submission fact, once written, does not change either.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER trg_dlv_answer_events_no_update BEFORE UPDATE ON dlv_answer_events FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'An answer event is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_dlv_answer_events_no_delete BEFORE DELETE ON dlv_answer_events FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'An answer event is never deleted\';
            END');

        DB::unprepared('DROP FUNCTION IF EXISTS cand_exam_is_submitted');
        DB::unprepared("CREATE FUNCTION cand_exam_is_submitted(p_candidate_exam_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM cand_candidate_exams WHERE id = p_candidate_exam_id;

                RETURN IF(v_status = 'submitted', 1, 0);
            END");

        // dlv_answer_events already refuses every UPDATE outright; only a new event needs this check.
        DB::unprepared("CREATE TRIGGER trg_dlv_answer_events_frozen_after_submit_insert BEFORE INSERT ON dlv_answer_events FOR EACH ROW
            BEGIN
                IF cand_exam_is_submitted(NEW.`candidate_exam_id`) = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This attempt has been submitted: no further answer can be recorded';
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER trg_dlv_answers_current_frozen_after_submit BEFORE UPDATE ON dlv_answers_current FOR EACH ROW
            BEGIN
                IF cand_exam_is_submitted(OLD.`candidate_exam_id`) = 1 THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This attempt has been submitted: no further answer can be recorded';
                END IF;
            END");

        DB::unprepared('DROP FUNCTION IF EXISTS cand_exam_is_not_started');
        DB::unprepared("CREATE FUNCTION cand_exam_is_not_started(p_candidate_exam_id BIGINT UNSIGNED)
            RETURNS TINYINT(1)
            READS SQL DATA
            DETERMINISTIC
            BEGIN
                DECLARE v_status VARCHAR(20);
                SELECT status INTO v_status FROM cand_candidate_exams WHERE id = p_candidate_exam_id;

                RETURN IF(v_status IS NULL OR v_status = 'not_started', 1, 0);
            END");

        foreach (['INSERT', 'UPDATE', 'DELETE'] as $event) {
            $row = $event === 'INSERT' ? 'NEW' : 'OLD';
            DB::unprepared('CREATE TRIGGER trg_cand_paper_items_frozen_'.strtolower($event)." BEFORE {$event} ON cand_paper_items FOR EACH ROW
                BEGIN
                    IF cand_exam_is_not_started({$row}.`candidate_exam_id`) = 0 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This attempt has started: its paper cannot be changed';
                    END IF;
                END");
        }

        DB::unprepared("CREATE TRIGGER trg_cand_candidate_exams_workflow BEFORE UPDATE ON cand_candidate_exams FOR EACH ROW
            BEGIN
                IF NOT (NEW.candidate_id <=> OLD.candidate_id) OR NOT (NEW.examination_id <=> OLD.examination_id) OR NOT (NEW.paper_id <=> OLD.paper_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An attempt cannot change whose it is, or which examination or paper it is for';
                END IF;

                IF NOT (NEW.status <=> OLD.status) THEN
                    IF (CASE
                        WHEN OLD.status = 'not_started' AND NEW.status NOT IN ('in_progress') THEN 1
                        WHEN OLD.status = 'in_progress' AND NEW.status NOT IN ('paused', 'submitted') THEN 1
                        WHEN OLD.status = 'paused' AND NEW.status NOT IN ('in_progress') THEN 1
                        WHEN OLD.status = 'submitted' THEN 1
                        ELSE 0
                    END) = 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That is not an allowed change to an attempt\\'s status';
                    END IF;
                END IF;
            END");
        DB::unprepared("CREATE TRIGGER trg_cand_candidate_exams_no_delete BEFORE DELETE ON cand_candidate_exams FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An attempt is never deleted';
            END");

        DB::unprepared('CREATE TRIGGER trg_dlv_submissions_no_update BEFORE UPDATE ON dlv_submissions FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A submission is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_dlv_submissions_no_delete BEFORE DELETE ON dlv_submissions FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A submission is never deleted\';
            END');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_submissions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_submissions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cand_candidate_exams_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cand_candidate_exams_workflow');
        foreach (['insert', 'update', 'delete'] as $event) {
            DB::unprepared("DROP TRIGGER IF EXISTS trg_cand_paper_items_frozen_{$event}");
        }
        DB::unprepared('DROP FUNCTION IF EXISTS cand_exam_is_not_started');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_answers_current_frozen_after_submit');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_answer_events_frozen_after_submit_insert');
        DB::unprepared('DROP FUNCTION IF EXISTS cand_exam_is_submitted');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_answer_events_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_answer_events_no_update');
    }
};
