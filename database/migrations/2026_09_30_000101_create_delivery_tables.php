<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sitting the exam (exam phase, step 18 — see ADR-0003 and docs/architecture/exam-phase.md).
 *
 * A candidate's attempt is stored, not the screen it happens to be showing: the paper they were
 * given, in their own question and option order, every answer, the timer, and which computer is
 * currently allowed to send answers for it. Any computer can rebuild the exact exam from this.
 *
 *  - cand_candidate_exams: one attempt — the paper assigned, the timer, and where it stands.
 *  - cand_paper_items: that paper's items in THIS candidate's order, with their own option order —
 *    fixed once the attempt starts, not re-shuffled if it is ever rebuilt.
 *  - dlv_sessions: one row per device sign-in, with a heartbeat, so a second sign-in can tell a
 *    silent (crashed) computer from one still working.
 *  - dlv_answer_events: every answer change, append-only and sequence-numbered — replaying the same
 *    sequence number twice does nothing, so a retried autosave is always safe.
 *  - dlv_answers_current: the current answer for each item, kept alongside the event log so resuming
 *    reads one row per item instead of replaying the whole history.
 *  - dlv_submissions: when the attempt was submitted, and by whom (the candidate, an invigilator, or
 *    the deadline itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cand_candidate_exams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_id')->constrained('cand_candidates')->restrictOnDelete();
            $table->foreignId('examination_id')->constrained('exm_examinations')->restrictOnDelete();
            $table->foreignId('paper_id')->constrained('exm_papers')->restrictOnDelete();

            $table->string('status', 20)->default('not_started')->comment('see App\\Domain\\Delivery\\Enums\\AttemptStatus');
            $table->dateTime('started_at', 3)->nullable();
            $table->dateTime('deadline_at', 3)->nullable()->comment('server clock; the only clock that matters while sitting');
            $table->dateTime('paused_at', 3)->nullable()->comment('set while the room is paused; the pause is added back to the deadline on resume');
            $table->unsignedInteger('extra_seconds')->default(0)->comment('pre-granted extra time plus any compensating time added mid-exam');
            $table->unsignedBigInteger('last_item_id')->nullable()->comment('cand_paper_items.id last viewed, for "continue where you left off"');

            $table->dateTime('submitted_at', 3)->nullable();
            $table->string('submitted_by', 20)->nullable()->comment('candidate, invigilator or auto (the deadline)');

            $table->timestamps();

            $table->unique(['candidate_id', 'examination_id']);
            $table->index(['examination_id', 'status']);
        });

        Schema::create('cand_paper_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->foreignId('paper_item_id')->constrained('exm_paper_items')->restrictOnDelete();
            $table->unsignedSmallInteger('position')->comment('this candidate\'s question order');
            $table->json('option_order')->nullable()->comment('this candidate\'s option order, by option id; null when not shuffled');
            $table->timestamps();

            $table->unique(['candidate_exam_id', 'paper_item_id']);
            $table->unique(['candidate_exam_id', 'position']);
        });

        Schema::create('dlv_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->char('token_hash', 64)->comment('SHA-256 of the bearer token this device holds; the token itself is never stored');
            $table->string('device', 255)->nullable()->comment('user agent, trimmed');
            $table->string('ip', 45)->nullable();
            $table->dateTime('started_at', 3);
            $table->dateTime('last_heartbeat_at', 3);
            $table->dateTime('ended_at', 3)->nullable();
            $table->string('end_reason', 20)->nullable()->comment('replaced, logged_out, submitted or revoked');
            $table->timestamps();

            $table->index(['candidate_exam_id', 'ended_at']);
        });

        Schema::create('dlv_answer_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->foreignId('cand_paper_item_id')->constrained('cand_paper_items')->restrictOnDelete();
            $table->unsignedInteger('sequence_no')->comment('assigned by the candidate\'s browser, monotonic per attempt');
            $table->json('payload')->comment('the answer as given: selected options, text, or an order — never a key');
            $table->boolean('flagged')->default(false);
            $table->dateTime('client_time', 3)->nullable()->comment('the browser\'s clock when the change was made, for the record only');
            $table->timestamps();

            $table->unique(['candidate_exam_id', 'sequence_no']);
            $table->index(['candidate_exam_id', 'cand_paper_item_id']);
        });

        Schema::create('dlv_answers_current', function (Blueprint $table) {
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->foreignId('cand_paper_item_id')->constrained('cand_paper_items')->restrictOnDelete();
            $table->json('payload')->nullable();
            $table->boolean('flagged')->default(false);
            $table->unsignedInteger('sequence_no')->default(0)->comment('the event this row reflects, so an older retry is never applied out of order');
            $table->timestamp('updated_at')->nullable();

            $table->primary(['candidate_exam_id', 'cand_paper_item_id']);
        });

        Schema::create('dlv_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->unique()->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->dateTime('submitted_at', 3);
            $table->string('submitted_by', 20)->comment('candidate, invigilator or auto');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dlv_submissions');
        Schema::dropIfExists('dlv_answers_current');
        Schema::dropIfExists('dlv_answer_events');
        Schema::dropIfExists('dlv_sessions');
        Schema::dropIfExists('cand_paper_items');
        Schema::dropIfExists('cand_candidate_exams');
    }
};
