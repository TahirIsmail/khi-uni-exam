<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A committee decision (step 19) can void an attempt from any state except one already voided —
 * including after submission, since a case is often only found once marking or a complaint follows.
 * This replaces the workflow trigger from migration 2026_09_30_000102 with one that also allows that
 * move, and makes 'voided' terminal the same way 'submitted' already is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cand_candidate_exams_workflow');
        DB::unprepared("CREATE TRIGGER trg_cand_candidate_exams_workflow BEFORE UPDATE ON cand_candidate_exams FOR EACH ROW
            BEGIN
                IF NOT (NEW.candidate_id <=> OLD.candidate_id) OR NOT (NEW.examination_id <=> OLD.examination_id) OR NOT (NEW.paper_id <=> OLD.paper_id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'An attempt cannot change whose it is, or which examination or paper it is for';
                END IF;

                IF NOT (NEW.status <=> OLD.status) THEN
                    IF (CASE
                        WHEN NEW.status = 'voided' THEN IF(OLD.status = 'voided', 1, 0)
                        WHEN OLD.status = 'not_started' AND NEW.status NOT IN ('in_progress') THEN 1
                        WHEN OLD.status = 'in_progress' AND NEW.status NOT IN ('paused', 'submitted') THEN 1
                        WHEN OLD.status = 'paused' AND NEW.status NOT IN ('in_progress') THEN 1
                        WHEN OLD.status = 'submitted' THEN 1
                        WHEN OLD.status = 'voided' THEN 1
                        ELSE 0
                    END) = 1 THEN
                        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'That is not an allowed change to an attempt\\'s status';
                    END IF;
                END IF;
            END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cand_candidate_exams_workflow');
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
    }
};
