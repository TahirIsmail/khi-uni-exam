<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Post-hoc decisions: what a department decides about a question *after* an examination, once there
 * are statistics for it (blueprint 9). The screens for this belong to the later post-hoc analysis
 * phase; the tables are created now because the first increment has to show that the three
 * judgements are separate records, each with its own history and its own authorised role:
 *
 *   - workflow status .......... qb_question_versions.status
 *   - pre-hoc decision ......... qb_prehoc_assessments (author proposal, reviewers, consolidated)
 *   - post-hoc decision ........ qb_posthoc_decisions (this file), one per version per examination
 *
 * Nothing writes to these tables yet, so no permission or route is added for them here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qb_posthoc_decision_types', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->boolean('keeps_question')->default(true)->comment('the question stays usable');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('qb_posthoc_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('qb_questions')->cascadeOnDelete();
            $table->unsignedInteger('branch_id');
            $table->unsignedBigInteger('exam_id')->nullable()->comment('the examination the statistics came from; the exam tables arrive in the delivery phase');
            $table->unsignedTinyInteger('decision_type_id');
            $table->decimal('observed_p', 5, 4)->nullable()->comment('proportion of candidates who answered correctly');
            $table->decimal('discrimination', 5, 4)->nullable()->comment('how well the question separated candidates');
            $table->unsignedInteger('candidates')->nullable();
            $table->string('reason', 500)->nullable();
            $table->foreignId('decided_by')->constrained('users');
            $table->dateTime('decided_at', 3);
            $table->timestamps();

            $table->foreign('decision_type_id')->references('id')->on('qb_posthoc_decision_types');
            $table->index(['version_id', 'decided_at']);
            $table->index(['branch_id', 'decided_at']);
            $table->index('exam_id');
        });

        // The client's list again: "Retain in QBank" and "Discard" are post-hoc outcomes, and a
        // question sent back for rewriting is neither retained as it is nor thrown away.
        $now = now();
        $rows = [
            ['retain', 'Retain in the question bank', 'Performed well enough to be used again.', true, 1],
            ['retain_watch', 'Retain, but watch it', 'Keep it and look again after the next examination.', true, 2],
            ['revise', 'Send back for revision', 'The statistics show a problem the author should fix.', true, 3],
            ['discard', 'Discard', 'Not to be used again.', false, 4],
        ];

        foreach ($rows as [$code, $name, $description, $keeps, $sort]) {
            DB::table('qb_posthoc_decision_types')->insert([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'keeps_question' => $keeps,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_posthoc_decisions');
        Schema::dropIfExists('qb_posthoc_decision_types');
    }
};
