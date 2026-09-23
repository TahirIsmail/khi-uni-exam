<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * A re-key is a permanent record of a correction, the same way every other decision log in this
 * module already is: doing it again is a new row, never an edit of the old one.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('CREATE TRIGGER trg_exm_item_rekeys_no_update BEFORE UPDATE ON exm_item_rekeys FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A re-key is never changed\';
            END');
        DB::unprepared('CREATE TRIGGER trg_exm_item_rekeys_no_delete BEFORE DELETE ON exm_item_rekeys FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A re-key is never deleted\';
            END');
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_exm_item_rekeys_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_exm_item_rekeys_no_update');
    }
};
