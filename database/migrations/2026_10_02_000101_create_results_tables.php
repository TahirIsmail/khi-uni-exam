<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Results (exam phase, step 21): raw scores until the pass percentage is applied, a controller
 * approves them, and they are published — three separate rights, so nobody sees a result before
 * both have happened. Re-keying a faulty item is a recorded correction to the paper's own item
 * (not the reusable question-bank record), and every affected candidate's mark is rescored.
 *
 *  - exm_results: one row per attempt, recomputed in place — a compiled cache of what
 *    mrk_item_marks already says, not a new fact of its own.
 *  - exm_result_publications: the approve/publish workflow, per examination, all attempts at once.
 *  - exm_item_rekeys: append-only — a correction to one paper item, and why.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->unique()->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->decimal('raw_marks', 6, 2);
            $table->decimal('negative_deduction', 6, 2)->default(0);
            $table->decimal('total_marks', 6, 2);
            $table->decimal('percentage', 5, 2);
            $table->boolean('is_pass')->default(false);
            $table->boolean('pending_items')->default(true)->comment('true while any item still lacks a final mark');
            $table->dateTime('compiled_at', 3);
            $table->timestamps();
        });

        Schema::create('exm_result_publications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->unique()->constrained('exm_examinations')->restrictOnDelete();
            $table->string('status', 20)->default('draft')->comment('draft, approved or published');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('published_at', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('exm_item_rekeys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_item_id')->constrained('exm_paper_items')->restrictOnDelete();
            $table->string('decision', 20)->comment('discard or correct_option');
            $table->unsignedBigInteger('corrected_option_id')->nullable();
            $table->string('reason', 500);
            $table->foreignId('decided_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('decided_at', 3);
            $table->timestamps();

            $table->index(['paper_item_id']);
            $table->foreign('corrected_option_id')->references('id')->on('qb_question_options')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_item_rekeys');
        Schema::dropIfExists('exm_result_publications');
        Schema::dropIfExists('exm_results');
    }
};
