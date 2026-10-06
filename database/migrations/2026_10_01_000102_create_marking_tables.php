<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Marking (exam phase, step 20): objective items are marked by the server against the sealed key;
 * short-answer and essay items are marked against the question's rubric, by one or two examiners
 * as the examination's own setting says, with a third opinion (adjudication) when two examiners
 * disagree beyond the threshold.
 *
 *  - mrk_examiner_assignments: who marks or adjudicates an examination's manually-marked items.
 *  - mrk_item_marks: one row per (attempt, item, source) — auto, each examiner, the adjudicator, or
 *    the agreed average — append-only, so every mark is a record of who gave what, and when.
 *  - mrk_item_mark_criteria: an essay mark's breakdown against the question's own rubric criteria.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mrk_examiner_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained('exm_examinations')->restrictOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->string('role', 20)->comment('see App\\Domain\\Marking\\Enums\\ExaminerRole');
            $table->foreignId('assigned_by')->constrained('users')->restrictOnDelete();
            $table->dateTime('assigned_at', 3);
            $table->timestamps();

            $table->index(['examination_id', 'role']);
        });

        Schema::create('mrk_item_marks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('candidate_exam_id')->constrained('cand_candidate_exams')->restrictOnDelete();
            $table->foreignId('cand_paper_item_id')->constrained('cand_paper_items')->restrictOnDelete();
            $table->string('source', 20)->comment('see App\\Domain\\Marking\\Enums\\MarkSource');
            $table->decimal('marks_awarded', 6, 2);
            $table->decimal('max_marks', 6, 2)->comment('the item\'s marks at the time this was recorded');
            $table->unsignedBigInteger('marked_by')->nullable()->comment('null for auto, and for a final row derived from agreement');
            $table->string('comments', 500)->nullable();
            $table->dateTime('marked_at', 3);
            $table->timestamps();

            $table->unique(['candidate_exam_id', 'cand_paper_item_id', 'source'], 'uq_mrk_item_marks_attempt_item_source');
            $table->index(['cand_paper_item_id']);
        });

        Schema::create('mrk_item_mark_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_mark_id')->constrained('mrk_item_marks')->cascadeOnDelete();
            $table->foreignId('rubric_criterion_id')->constrained('qb_question_rubric_criteria')->restrictOnDelete();
            $table->decimal('marks_awarded', 6, 2);
            $table->timestamps();

            $table->unique(['item_mark_id', 'rubric_criterion_id'], 'uq_mrk_item_mark_criteria');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mrk_item_mark_criteria');
        Schema::dropIfExists('mrk_item_marks');
        Schema::dropIfExists('mrk_examiner_assignments');
    }
};
