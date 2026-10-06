<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Browser lockdown and proctoring (exam phase, step 19). The candidate's own browser enforces
 * fullscreen and blocks copy, paste, right-click, print and devtools; every attempt at any of them,
 * and every tab-visibility change, is reported here as a record, not silently swallowed.
 *
 *  - dlv_proctor_events: append-only, one row per reported event, with a severity assigned by type.
 *  - dlv_proctor_decisions: the committee's call against a case — no action, a warning, flagged for
 *    review, or voiding the attempt — never changed once written.
 *  - cand_devices: the device (browser + machine) a candidate's attempt was first seen on, at this
 *    centre. A new one needs an invigilator's one-time approval before the attempt may proceed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dlv_proctor_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->foreignId('session_id')->nullable()->constrained('dlv_sessions')->restrictOnDelete();
            $table->string('type', 30)->comment('see App\\Domain\\Delivery\\Enums\\ProctorEventType');
            $table->string('severity', 10)->comment('see App\\Domain\\Delivery\\Enums\\ProctorSeverity');
            $table->json('detail')->nullable()->comment('e.g. the device fingerprint or fullscreen state');
            $table->dateTime('occurred_at', 3);
            $table->timestamps();

            $table->index(['candidate_exam_id', 'occurred_at']);
            $table->index(['candidate_exam_id', 'severity']);
        });

        Schema::create('dlv_proctor_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->dateTime('covers_from', 3)->nullable()->comment('the case reviewed: events from this time');
            $table->dateTime('covers_to', 3)->nullable()->comment('the case reviewed: events up to this time');
            $table->string('decision', 20)->comment('see App\\Domain\\Delivery\\Enums\\ProctorDecisionType');
            $table->string('reason', 500);
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at', 3);
            $table->timestamps();

            $table->index(['candidate_exam_id']);
        });

        Schema::create('cand_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained('cand_centres')->restrictOnDelete();
            $table->char('device_fingerprint', 64)->comment('SHA-256 of the browser-reported user agent, screen and time zone');
            $table->foreignId('first_seen_candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->dateTime('approved_at', 3)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamps();

            $table->unique(['centre_id', 'device_fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cand_devices');
        Schema::dropIfExists('dlv_proctor_decisions');
        Schema::dropIfExists('dlv_proctor_events');
    }
};
