<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A mark, once recorded, is a fact: who gave what, and when. Neither mrk_item_marks nor its rubric
 * breakdown is ever changed or deleted — a correction is step 21's rescoring, a new record against
 * the same item, not an edit of this one. Same pattern as the delivery and proctoring logs
 * (migrations 2026_09_30_000102, 2026_09_30_000202).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER trg_mrk_item_marks_no_update BEFORE UPDATE ON mrk_item_marks FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A mark is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_mrk_item_marks_no_delete BEFORE DELETE ON mrk_item_marks FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A mark is never deleted\';
            END');

        DB::unprepared('CREATE TRIGGER trg_mrk_item_mark_criteria_no_update BEFORE UPDATE ON mrk_item_mark_criteria FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A rubric mark is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_mrk_item_mark_criteria_no_delete BEFORE DELETE ON mrk_item_mark_criteria FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A rubric mark is never deleted\';
            END');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_mrk_item_mark_criteria_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_mrk_item_mark_criteria_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_mrk_item_marks_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_mrk_item_marks_no_update');
    }
};
