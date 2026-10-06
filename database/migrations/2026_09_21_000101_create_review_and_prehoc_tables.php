<?php

use App\Support\Cms\CmsViews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Review, pre-hoc assessment and approval (blueprint 9 and P1.8).
 *
 * Three concepts stay separate, each with its own history and its own authorised role:
 *  - the workflow status of a version (qb_question_versions.status) says where it is in the process;
 *  - a review (qb_reviews) is one reviewer's outcome: either "request changes" or a decision with a
 *    pre-hoc judgement and the item-writing checklist;
 *  - a pre-hoc assessment (qb_prehoc_assessments) is the expert judgement itself — the author's
 *    proposal, each reviewer's values, and the one consolidated row the approver settles on, which
 *    is copied onto the version when it is approved.
 *
 * A review cannot be changed once submitted (triggers below), like the status log and the audit log.
 * The settings that steer this (how many reviews, how long, auto-activation, reviewer anonymity)
 * are administered in kmu-cms, so the settings view is recreated here with them.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The pre-hoc outcomes the client's list asks for. "Retain in QBank" and "Discard" are
        // post-hoc decisions (after exam data) and are not part of this list.
        Schema::create('qb_prehoc_decisions', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement();
            $table->string('code', 30)->unique();
            $table->string('name', 60);
            $table->string('description', 255)->nullable();
            $table->boolean('is_accept')->default(false)->comment('counts as a pass at the approval gate');
            $table->boolean('needs_comment')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // The item-writing checklist a reviewer works through (NBME item-writing guide). Configurable:
        // adding a rule is a new row, and a rule that is no longer wanted is deactivated.
        Schema::create('qb_review_checklist_items', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->autoIncrement();
            $table->string('code', 40)->unique();
            $table->string('text', 200);
            $table->string('guidance', 255)->nullable();
            $table->string('applies_to', 40)->nullable()->comment('question type family, null = every type');
            $table->boolean('is_required')->default(true)->comment('must pass before a question can be approved');
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Who has to review which version. The author is never assigned; an admin can reassign.
        Schema::create('qb_review_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('qb_questions')->cascadeOnDelete();
            $table->unsignedInteger('branch_id')->comment('campus, from the version');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->foreignId('assigned_by')->nullable()->comment('null = assigned automatically')->constrained('users');
            $table->enum('status', ['open', 'submitted', 'cancelled'])->default('open');
            $table->dateTime('due_at', 3)->nullable();
            $table->dateTime('assigned_at', 3);
            $table->dateTime('submitted_at', 3)->nullable();
            $table->dateTime('cancelled_at', 3)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['reviewer_id', 'status', 'due_at'], 'idx_qb_assignments_queue');
            $table->index(['branch_id', 'status']);
        });

        // One reviewer's submitted outcome. Immutable: to say something else, a new review is needed.
        Schema::create('qb_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->unique()->constrained('qb_review_assignments')->cascadeOnDelete();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('qb_questions')->cascadeOnDelete();
            $table->unsignedInteger('branch_id');
            $table->foreignId('reviewer_id')->constrained('users');
            $table->enum('outcome', ['reviewed', 'changes_requested']);
            $table->unsignedTinyInteger('decision_id')->nullable()->comment('pre-hoc decision; null when changes are requested');
            $table->mediumText('comments')->nullable()->comment('plain text, shown to the author');
            $table->json('checklist')->nullable()->comment('[{code, pass, note}] against qb_review_checklist_items');
            $table->dateTime('submitted_at', 3);
            $table->timestamps();

            $table->foreign('decision_id')->references('id')->on('qb_prehoc_decisions');
            $table->index(['version_id', 'outcome']);
            $table->index(['branch_id', 'submitted_at']);
        });

        // The pre-hoc judgement itself: the author's proposal, one row per reviewer, and the single
        // consolidated row the approver settles on.
        Schema::create('qb_prehoc_assessments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('qb_questions')->cascadeOnDelete();
            $table->unsignedInteger('branch_id');
            $table->foreignId('review_id')->nullable()->comment('null for the author proposal and the consolidated row')->constrained('qb_reviews')->cascadeOnDelete();
            $table->enum('source', ['author', 'reviewer', 'consolidated']);
            $table->unsignedTinyInteger('cognitive_level_id')->nullable();
            $table->unsignedTinyInteger('difficulty_level_id')->nullable();
            $table->decimal('estimated_p', 4, 3)->nullable()->comment('expected proportion of candidates answering correctly');
            $table->unsignedTinyInteger('decision_id')->nullable();
            $table->string('reason', 500)->nullable()->comment('why the approver settled on these values');
            $table->boolean('is_consolidated')->default(false);
            $table->foreignId('assessed_by')->constrained('users');
            $table->dateTime('assessed_at', 3);
            $table->timestamps();

            $table->foreign('cognitive_level_id')->references('id')->on('qb_cognitive_levels');
            $table->foreign('difficulty_level_id')->references('id')->on('qb_difficulty_levels');
            $table->foreign('decision_id')->references('id')->on('qb_prehoc_decisions');
            $table->index(['version_id', 'source']);
            $table->index(['branch_id', 'assessed_at']);
        });

        // Nobody is asked twice at the same time, but a reviewer who has already reviewed a version
        // can be asked again after the author has changed it — so only open assignments are unique.
        DB::statement('ALTER TABLE `qb_review_assignments`
            ADD COLUMN `open_reviewer_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (IF(`status` = \'open\', `reviewer_id`, NULL)) VIRTUAL,
            ADD UNIQUE KEY `uq_qb_assignments_open_reviewer` (`version_id`, `open_reviewer_key`)');

        // One consolidated row per version, enforced by the database (the same trick as the active
        // version of a question: a virtual column that is null unless the row is the consolidated one).
        DB::statement('ALTER TABLE `qb_prehoc_assessments`
            ADD COLUMN `consolidated_version_key` BIGINT UNSIGNED
                GENERATED ALWAYS AS (IF(`is_consolidated` = 1, `version_id`, NULL)) VIRTUAL,
            ADD UNIQUE KEY `uq_qb_prehoc_consolidated` (`consolidated_version_key`)');

        // A submitted review is a record of what someone said at a point in time.
        DB::unprepared('CREATE TRIGGER qb_reviews_no_update BEFORE UPDATE ON qb_reviews FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A submitted review cannot be changed.\';
            END');
        DB::unprepared('CREATE TRIGGER qb_reviews_no_delete BEFORE DELETE ON qb_reviews FOR EACH ROW
            BEGIN
                SIGNAL SQLSTATE \'45000\' SET MESSAGE_TEXT = \'A submitted review cannot be deleted.\';
            END');

        $this->seedDecisions();
        $this->seedChecklist();

        // The CMS settings view now carries the review settings as well.
        $definitions = CmsViews::definitions((string) config('database.cms_source_database'));
        DB::statement('CREATE OR REPLACE SQL SECURITY DEFINER VIEW `v_cms_exam_settings` AS '.$definitions['v_cms_exam_settings']); // raw-sql-reviewed: view text comes from CmsViews, no user input
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS qb_reviews_no_update');
        DB::unprepared('DROP TRIGGER IF EXISTS qb_reviews_no_delete');
        Schema::dropIfExists('qb_prehoc_assessments');
        Schema::dropIfExists('qb_reviews');
        Schema::dropIfExists('qb_review_assignments');
        Schema::dropIfExists('qb_review_checklist_items');
        Schema::dropIfExists('qb_prehoc_decisions');
    }

    private function seedDecisions(): void
    {
        $now = now();
        $rows = [
            ['accept', 'Accept', 'Good enough to be used in an examination as it is.', true, false, 1],
            ['accept_minor', 'Accept with minor changes', 'Usable once the small points in the comments are fixed.', true, true, 2],
            ['revise', 'Revise and review again', 'Send it back to the author; it needs another review afterwards.', false, true, 3],
            ['hold', 'Hold for discussion', 'Keep it in the bank but do not use it until the department decides.', false, true, 4],
            ['remove', 'Do not use', 'Not salvageable; archive it with the reason.', false, true, 5],
        ];

        foreach ($rows as [$code, $name, $description, $isAccept, $needsComment, $sort]) {
            DB::table('qb_prehoc_decisions')->insert([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'is_accept' => $isAccept,
                'needs_comment' => $needsComment,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function seedChecklist(): void
    {
        $now = now();
        // The NBME item-writing rules the university asked for. The first six must pass.
        $rows = [
            ['cover_the_options', 'A knowledgeable candidate could answer without seeing the options', 'Cover the options and read the vignette and lead-in: the answer should still come.', null, true, 1],
            ['no_negative_stem', 'The lead-in is not negative (no "except", "not true")', 'Ask what is true, not what is false.', null, true, 2],
            ['homogeneous_options', 'The options are of one kind and similar in length and detail', 'All investigations, or all diagnoses — not a mixture.', 'choice', true, 3],
            ['no_absolute_terms', 'No absolute terms ("always", "never") and no "all of the above"', null, 'choice', true, 4],
            ['no_grammatical_cues', 'No grammatical or spelling clue points to the key', 'Each option must read correctly after the lead-in.', 'choice', true, 5],
            ['key_not_longest', 'The correct option is not the longest or most detailed one', null, 'choice', true, 6],
            ['answer_defensible', 'The key is defensible from the reference given', 'The reference should name the edition and page.', null, false, 7],
            ['clinically_relevant', 'The question is clinically relevant and free of jargon and abbreviations', null, null, false, 8],
        ];

        foreach ($rows as [$code, $text, $guidance, $appliesTo, $required, $sort]) {
            DB::table('qb_review_checklist_items')->insert([
                'code' => $code,
                'text' => $text,
                'guidance' => $guidance,
                'applies_to' => $appliesTo,
                'is_required' => $required,
                'sort_order' => $sort,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
};
