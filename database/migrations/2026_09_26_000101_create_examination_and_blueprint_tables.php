<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The examination and its blueprint (exam phase, steps 1 and 2 — see docs/architecture/exam-phase.md).
 *
 *  - An examination is one sitting of one Course ID: what it is (programme, year or semester,
 *    examination type, course), when, for how long, out of how many marks, and how it is marked.
 *  - Its blueprint is the Table of Specification written before any question is chosen: rows of
 *    topic × type of question × how many × marks each, and the overall cognitive and difficulty mix.
 *
 * Every row carries branch_id, the campus, as in the question bank. programme_id, course_id, node_id
 * and the rest point into kmu-cms; MySQL cannot enforce those across databases, so the application
 * checks them against the read-only v_cms_* views (ADR-0004).
 *
 * exam_id in qb_question_usage and qb_posthoc_decisions, created earlier as a bare number, now has
 * something to point at: exm_examinations.id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_examinations', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 20)->unique()->comment('human reference, e.g. EX-2026-0001');
            $table->unsignedInteger('branch_id')->comment('kmu-cms branches.id');
            $table->string('title', 200);

            // Where it sits in the academic structure (kmu-cms): the same chain a question is filed under.
            $table->unsignedInteger('programme_id')->comment('kmu-cms classes.id');
            $table->unsignedInteger('professional_id')->comment('kmu-cms acad_professionals.id: the year');
            $table->unsignedInteger('term_id')->nullable()->comment('kmu-cms acad_professional_terms.id: the semester, for semester programmes');
            $table->unsignedInteger('course_id')->comment('kmu-cms acad_courses.id: one Course ID per examination');
            $table->unsignedInteger('exam_type_id')->comment('kmu-cms acad_exam_types.id: Annual, Supplementary, Regular, Retake');
            $table->unsignedInteger('intake_id')->nullable()->comment('kmu-cms sessions.id: the academic session');

            $table->dateTime('starts_at')->nullable()->comment('UTC; shown and entered in the examination time zone (config/exam.php)');
            $table->unsignedSmallInteger('duration_minutes');
            $table->decimal('total_marks', 7, 2);
            $table->decimal('pass_percentage', 5, 2)->default(50);
            $table->boolean('negative_marking')->default(false);
            $table->decimal('negative_fraction', 4, 3)->nullable()->comment('share of the question\'s marks lost for a wrong answer; empty = the question\'s own negative marks');
            $table->text('instructions')->nullable()->comment('plain text shown to candidates before they start');

            $table->string('status', 30)->default('draft')->comment('see App\\Domain\\Exam\\Enums\\ExaminationStatus');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
            $table->index(['course_id', 'status']);
            $table->index(['programme_id', 'professional_id']);
            $table->index('starts_at');
        });

        // Parts of the paper, when the examination has them (Section A multiple choice, Section B short answer).
        Schema::create('exm_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained('exm_examinations')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['examination_id', 'name']);
        });

        Schema::create('exm_blueprints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->unique()->constrained('exm_examinations')->cascadeOnDelete();
            $table->unsignedInteger('branch_id');
            $table->string('status', 20)->default('draft')->comment('draft, submitted, approved');

            $table->unsignedBigInteger('submitted_by')->nullable();
            $table->dateTime('submitted_at', 3)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->char('approved_hash', 64)->nullable()->comment('SHA-256 of the blueprint as approved, so a paper can show it was built to exactly this');
            $table->string('return_reason', 500)->nullable()->comment('why the approver sent it back; cleared when it is submitted again');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('exm_blueprint_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('exm_blueprints')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('section_id')->nullable()->constrained('exm_sections')->nullOnDelete();
            $table->unsignedInteger('node_id')->comment('kmu-cms acad_curriculum_nodes.id: the topic (its subtopics count too)');
            $table->unsignedInteger('discipline_id')->nullable()->comment('copied from the topic, so a blueprint can be read by discipline');
            $table->unsignedTinyInteger('question_type_id');
            $table->unsignedSmallInteger('question_count');
            $table->decimal('marks_each', 6, 2);
            $table->timestamps();

            // The same topic, type, marks and section twice is one row with a higher count.
            $table->string('row_key', 60)->virtualAs("CONCAT_WS('-', node_id, question_type_id, marks_each, IFNULL(section_id, 0))");

            $table->unique(['blueprint_id', 'row_key'], 'uq_exm_blueprint_row');
            $table->index('node_id');
            $table->foreign('question_type_id')->references('id')->on('qb_question_types')->restrictOnDelete();
        });

        // The overall mix the paper should have: percentages by cognitive level and by difficulty level.
        Schema::create('exm_blueprint_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blueprint_id')->constrained('exm_blueprints')->cascadeOnDelete();
            $table->enum('dimension', ['cognitive', 'difficulty']);
            $table->unsignedTinyInteger('level_id')->comment('qb_cognitive_levels.id or qb_difficulty_levels.id, by dimension');
            $table->decimal('percent', 5, 2);
            $table->timestamps();

            $table->unique(['blueprint_id', 'dimension', 'level_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_blueprint_targets');
        Schema::dropIfExists('exm_blueprint_rows');
        Schema::dropIfExists('exm_blueprints');
        Schema::dropIfExists('exm_sections');
        Schema::dropIfExists('exm_examinations');
    }
};
