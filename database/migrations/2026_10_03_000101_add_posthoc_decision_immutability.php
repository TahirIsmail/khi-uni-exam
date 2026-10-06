<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A post-hoc decision, once recorded, is a fact — the same promise every other decision log in
 * this module already makes (migrations 2026_09_30_000202, 2026_10_02_000102, ...). A further
 * decision about the same question is a new row, never an edit of an old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER trg_qb_posthoc_decisions_no_update BEFORE UPDATE ON qb_posthoc_decisions FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A post-hoc decision is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_qb_posthoc_decisions_no_delete BEFORE DELETE ON qb_posthoc_decisions FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A post-hoc decision is never deleted\';
            END');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_qb_posthoc_decisions_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_qb_posthoc_decisions_no_update');
    }
};
