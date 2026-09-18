<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Where a question has been used: one row per version per examination. The university's post-hoc
 * report asks for a question's history — how many times it was used, in which examination and on
 * what date, and how many candidates attempted it — and that can only be answered per examination,
 * not by a counter.
 *
 * Like qb_posthoc_decisions this is created now so the shape is right from the start; the exam
 * tables and the screens that fill it belong to the delivery and post-hoc phases. The running
 * totals on qb_questions (times_used, candidates_total, last_used_at, last_p, last_d) stay as the
 * quick figures the search and the question page show.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qb_question_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('qb_questions')->cascadeOnDelete();
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('exam_id')->nullable()->comment('the examination; its tables arrive in the delivery phase');
            $table->string('exam_label', 200)->nullable()->comment('e.g. MBBS First Professional Annual Examination 2026');
            $table->date('used_on')->nullable();
            $table->unsignedInteger('candidates')->nullable()->comment('how many attempted it');
            $table->unsignedInteger('correct_count')->nullable();
            $table->decimal('observed_p', 5, 4)->nullable()->comment('difficulty index');
            $table->decimal('discrimination', 5, 4)->nullable()->comment('discrimination index');
            $table->json('option_shares')->nullable()->comment('distractor analysis: share of candidates per option');
            $table->timestamps();

            $table->unique(['version_id', 'exam_id']);
            $table->index(['question_id', 'used_on']);
            $table->index(['branch_id', 'used_on']);
            $table->index('exam_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_question_usage');
    }
};
