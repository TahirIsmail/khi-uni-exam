<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Once a candidate has checked in, who they are is fixed: their candidate number and which
 * examination they sat cannot change, by any route, and the row is never deleted — the same promise
 * the question bank and the paper already make for what they freeze. Everything else about a
 * candidate (allocation, extra time) can still be corrected; only their identity and the fact that
 * they sat is locked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared("CREATE TRIGGER trg_cand_candidates_frozen BEFORE UPDATE ON cand_candidates FOR EACH ROW
            BEGIN
                IF OLD.checked_in_at IS NOT NULL AND (
                    NOT (NEW.examination_id <=> OLD.examination_id) OR NOT (NEW.candidate_no <=> OLD.candidate_no)
                ) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'This candidate has checked in: their number and examination cannot be changed';
                END IF;
            END");

        DB::unprepared("CREATE TRIGGER trg_cand_candidates_no_delete BEFORE DELETE ON cand_candidates FOR EACH ROW
            BEGIN
                IF OLD.checked_in_at IS NOT NULL THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'A candidate who has checked in is never deleted';
                END IF;
            END");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cand_candidates_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_cand_candidates_frozen');
    }
};
