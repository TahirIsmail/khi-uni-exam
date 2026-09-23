<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A proctoring event is a record of what happened, and a committee decision is a record of what
 * was decided about it: neither is ever changed or removed, the same promise every other append-only
 * log in this module already makes (migrations 2026_09_30_000102, 2026_09_29_000103).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER trg_dlv_proctor_events_no_update BEFORE UPDATE ON dlv_proctor_events FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A proctoring event is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_dlv_proctor_events_no_delete BEFORE DELETE ON dlv_proctor_events FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A proctoring event is never deleted\';
            END');

        DB::unprepared('CREATE TRIGGER trg_dlv_proctor_decisions_no_update BEFORE UPDATE ON dlv_proctor_decisions FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A proctoring decision is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_dlv_proctor_decisions_no_delete BEFORE DELETE ON dlv_proctor_decisions FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A proctoring decision is never deleted\';
            END');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_proctor_decisions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_proctor_decisions_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_proctor_events_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_dlv_proctor_events_no_update');
    }
};
