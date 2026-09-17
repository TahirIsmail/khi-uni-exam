<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Tamper-evident audit log (blueprint 22.5).
 *
 * - Append-only: triggers reject UPDATE and DELETE on sec_audit_logs, whoever runs them.
 * - Hash chain: row_hash = SHA-256(prev_hash + canonical row). Writers lock sec_audit_chain_head, so
 *   rows are chained one at a time; `php artisan audit:verify` recomputes the chain and reports the
 *   first row that no longer matches (for example after a direct edit with triggers dropped).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sec_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->dateTime('occurred_at', 3);
            $table->string('actor_type', 20)->comment('staff, system');
            $table->unsignedBigInteger('actor_id')->nullable()->comment('users.id');
            $table->string('action', 80);
            $table->string('entity_type', 60)->nullable();
            $table->string('entity_id', 64)->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('reason', 500)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->char('session_hash', 64)->nullable();
            $table->char('request_id', 36)->nullable();
            $table->char('prev_hash', 64);
            $table->char('row_hash', 64)->unique();

            $table->index(['entity_type', 'entity_id']);
            $table->index(['actor_id', 'occurred_at']);
            $table->index(['action', 'occurred_at']);
            $table->index('occurred_at');
        });

        Schema::create('sec_audit_chain_head', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedBigInteger('last_log_id')->nullable();
            $table->char('last_hash', 64);
        });

        DB::table('sec_audit_chain_head')->insert(['id' => 1, 'last_log_id' => null, 'last_hash' => str_repeat('0', 64)]);

        DB::unprepared("CREATE TRIGGER trg_sec_audit_logs_no_update BEFORE UPDATE ON sec_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log is append-only'");
        DB::unprepared("CREATE TRIGGER trg_sec_audit_logs_no_delete BEFORE DELETE ON sec_audit_logs FOR EACH ROW
            SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'The audit log is append-only'");
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sec_audit_logs_no_delete');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_sec_audit_logs_no_update');
        Schema::dropIfExists('sec_audit_chain_head');
        Schema::dropIfExists('sec_audit_logs');
    }
};
