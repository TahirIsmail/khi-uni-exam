<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The question bank (blueprint 8): a question is a stable identity with a chain of versions.
 * Content lives on the version; the workflow status belongs to the version; only one version of a
 * question can be active at a time. Approved and later versions are frozen (see the next migration).
 *
 * Every row carries branch_id: the campus it belongs to, taken from the campus the author is working
 * in. course_id, node_id and the rest point into kmu-cms; MySQL cannot enforce those across
 * databases, so the application validates them against the read-only v_cms_* views (ADR-0004).
 */
return new class extends Migration
{
    private const STATUSES = ['draft', 'submitted', 'under_review', 'changes_requested', 'approved', 'active', 'on_hold', 'superseded', 'retired', 'archived'];

    public function up(): void
    {
        Schema::create('qb_questions', function (Blueprint $table) {
            $table->id();
            $table->string('public_ref', 20)->unique()->comment('human reference, e.g. Q-2026-000123');
            $table->unsignedInteger('branch_id')->comment('kmu-cms branches.id');
            $table->unsignedInteger('course_id')->comment('kmu-cms acad_courses.id (owning course)');
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->unsignedSmallInteger('latest_version_no')->default(0);

            $table->unsignedInteger('times_used')->default(0)->comment('cached from exam usage');
            $table->unsignedInteger('candidates_total')->default(0)->comment('cached');
            $table->dateTime('last_used_at', 3)->nullable()->comment('cached');
            $table->decimal('last_p', 5, 4)->nullable()->comment('cached difficulty index');
            $table->decimal('last_d', 5, 4)->nullable()->comment('cached discrimination index');

            $table->boolean('is_archived')->default(false)->comment('soft delete: questions are archived, never deleted');
            $table->dateTime('archived_at', 3)->nullable();
            $table->unsignedBigInteger('archived_by')->nullable();
            $table->string('archive_reason', 500)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'course_id', 'is_archived']);
            $table->index(['course_id', 'is_archived']);
        });

        Schema::create('qb_question_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('qb_questions')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no');
            $table->unsignedTinyInteger('question_type_id');
            $table->unsignedInteger('branch_id');

            $table->mediumText('vignette')->nullable()->comment('clinical scenario, sanitised HTML');
            $table->mediumText('stem')->comment('sanitised HTML');
            $table->string('lead_in', 500)->nullable()->comment('the actual question, e.g. "Which is the most likely diagnosis?"');
            $table->mediumText('explanation')->nullable();
            $table->json('settings')->nullable()->comment('type settings (shuffle, partial credit, word limits, ...)');
            $table->decimal('marks', 6, 2)->default(1);
            $table->decimal('negative_marks', 6, 2)->default(0);

            // Where in the academic structure (kmu-cms); node_id is required, the rest are copies for filtering.
            $table->unsignedInteger('programme_id')->nullable();
            $table->unsignedInteger('professional_id')->nullable();
            $table->unsignedInteger('term_id')->nullable();
            $table->unsignedInteger('course_id');
            $table->unsignedInteger('node_id')->comment('acad_curriculum_nodes.id: the topic this question belongs to');
            $table->unsignedInteger('discipline_id')->nullable();
            $table->unsignedTinyInteger('cognitive_level_id')->nullable();
            $table->unsignedTinyInteger('difficulty_level_id')->nullable();

            $table->enum('status', self::STATUSES)->default('draft');
            $table->char('content_hash', 64)->comment('SHA-256 of the normalised stem and options, for duplicate detection');
            $table->mediumText('search_text')->comment('plain text of stem, options and explanation, for search');

            $table->enum('source', ['manual', 'import', 'ai_draft'])->default('manual');
            $table->unsignedBigInteger('import_row_id')->nullable();
            $table->unsignedBigInteger('author_id')->comment('users.id of the author');
            $table->dateTime('submitted_at', 3)->nullable();
            $table->dateTime('approved_at', 3)->nullable();
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->dateTime('activated_at', 3)->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            // Only one active version per question, enforced by the database.
            $table->unsignedBigInteger('active_question_key')->virtualAs("IF(status = 'active', question_id, NULL)");

            $table->unique(['question_id', 'version_no']);
            $table->unique('active_question_key', 'uq_qb_one_active_version');
            $table->fullText('search_text', 'ft_qb_versions_search');
            $table->index(['branch_id', 'status']);
            $table->index(['course_id', 'status']);
            $table->index(['node_id', 'status']);
            $table->index(['discipline_id', 'status']);
            $table->index('content_hash');
            $table->index(['author_id', 'status']);
            $table->foreign('question_type_id')->references('id')->on('qb_question_types')->restrictOnDelete();
            $table->foreign('cognitive_level_id')->references('id')->on('qb_cognitive_levels')->restrictOnDelete();
            $table->foreign('difficulty_level_id')->references('id')->on('qb_difficulty_levels')->restrictOnDelete();
        });

        DB::statement('ALTER TABLE qb_questions ADD CONSTRAINT fk_qb_questions_active_version FOREIGN KEY (active_version_id) REFERENCES qb_question_versions (id) ON DELETE SET NULL');

        // Sub-parts: true/false statements, EMQ lead-ins, matching prompts, cloze blanks, ordering items.
        Schema::create('qb_question_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->comment('also the correct position for "put in order"');
            $table->mediumText('body')->comment('the statement, prompt or blank label');
            $table->boolean('is_true')->nullable()->comment('true/false statements');
            $table->unsignedBigInteger('correct_option_id')->nullable()->comment('matching and EMQ: the right option');
            $table->decimal('marks_fraction', 5, 4)->nullable()->comment('share of the marks, when not equal');
            $table->string('feedback', 500)->nullable();
            $table->json('settings')->nullable()->comment('e.g. the image area for labelling');
            $table->timestamps();

            $table->unique(['version_id', 'sort_order']);
        });

        Schema::create('qb_question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id')->nullable()->comment('set when the option belongs to one sub-part only');
            $table->string('label', 4)->comment('A, B, C ... or 1, 2, 3');
            $table->mediumText('body');
            $table->boolean('is_correct')->default(false);
            $table->decimal('weight', 5, 4)->nullable()->comment('partial credit for this option');
            $table->string('feedback', 500)->nullable();
            $table->unsignedSmallInteger('sort_order');
            $table->boolean('is_position_locked')->default(false)->comment('stays put when options are shuffled');
            $table->timestamps();

            $table->unsignedBigInteger('item_key')->virtualAs('IFNULL(item_id, 0)');
            $table->unique(['version_id', 'item_key', 'label'], 'uq_qb_options_label');
            $table->unique(['version_id', 'item_key', 'sort_order'], 'uq_qb_options_order');
            $table->index(['version_id', 'is_correct']);
            $table->foreign('item_id')->references('id')->on('qb_question_items')->cascadeOnDelete();
        });

        DB::statement('ALTER TABLE qb_question_items ADD CONSTRAINT fk_qb_items_correct_option FOREIGN KEY (correct_option_id) REFERENCES qb_question_options (id) ON DELETE SET NULL');

        // Typed answers: short answer, numerical, cloze blanks.
        Schema::create('qb_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->unsignedBigInteger('item_id')->nullable()->comment('which blank this answer belongs to');
            $table->enum('match_mode', ['exact', 'contains', 'regex', 'numeric'])->default('exact');
            $table->string('answer_text', 255)->nullable();
            $table->boolean('case_sensitive')->default(false);
            $table->decimal('numeric_value', 20, 6)->nullable();
            $table->decimal('tolerance', 20, 6)->nullable();
            $table->enum('tolerance_type', ['absolute', 'relative'])->default('absolute');
            $table->string('unit', 30)->nullable();
            $table->decimal('marks_fraction', 5, 4)->default(1)->comment('1 = full marks for this answer');
            $table->string('feedback', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['version_id', 'item_id']);
            $table->foreign('item_id')->references('id')->on('qb_question_items')->cascadeOnDelete();
        });

        Schema::create('qb_question_rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order');
            $table->string('criterion', 255);
            $table->decimal('max_marks', 6, 2);
            $table->text('guidance')->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'sort_order']);
        });

        Schema::create('qb_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->enum('kind', ['book', 'journal', 'guideline', 'url', 'other'])->default('book');
            $table->string('citation', 500);
            $table->string('locator', 100)->nullable()->comment('edition, page, chapter');
            $table->string('url', 500)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index('version_id');
        });

        Schema::create('qb_media', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->string('disk', 30)->default('local');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->char('checksum', 64)->comment('SHA-256, so the same file is stored once');
            $table->unsignedSmallInteger('width')->nullable();
            $table->unsignedSmallInteger('height')->nullable();
            $table->string('alt_text', 255)->comment('required: screen readers and printed papers');
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'checksum']);
        });

        Schema::create('qb_version_media', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('qb_media')->restrictOnDelete();
            $table->enum('role', ['vignette', 'stem', 'option', 'item', 'explanation'])->default('stem');
            $table->unsignedBigInteger('target_id')->nullable()->comment('the option or item it belongs to');
            $table->unsignedSmallInteger('sort_order')->default(1);
            $table->timestamps();

            $table->index(['version_id', 'role']);
        });

        Schema::create('qb_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id');
            $table->string('name', 60);
            $table->string('slug', 60);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'slug']);
        });

        Schema::create('qb_version_tags', function (Blueprint $table) {
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('qb_tags')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->primary(['version_id', 'tag_id']);
            $table->index('tag_id');
        });

        // Append-only record of every status change (the next migration blocks updates and deletes).
        Schema::create('qb_version_status_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('qb_question_versions')->cascadeOnDelete();
            $table->enum('from_status', self::STATUSES)->nullable();
            $table->enum('to_status', self::STATUSES);
            $table->unsignedBigInteger('actor_id')->nullable()->comment('users.id; null for the system');
            $table->string('reason', 500)->nullable();
            $table->dateTime('occurred_at', 3);

            $table->index(['version_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qb_version_status_log');
        Schema::dropIfExists('qb_version_tags');
        Schema::dropIfExists('qb_tags');
        Schema::dropIfExists('qb_version_media');
        Schema::dropIfExists('qb_media');
        Schema::dropIfExists('qb_references');
        Schema::dropIfExists('qb_question_rubric_criteria');
        Schema::dropIfExists('qb_question_answers');
        DB::statement('ALTER TABLE qb_question_items DROP FOREIGN KEY fk_qb_items_correct_option');
        Schema::dropIfExists('qb_question_options');
        Schema::dropIfExists('qb_question_items');
        DB::statement('ALTER TABLE qb_questions DROP FOREIGN KEY fk_qb_questions_active_version');
        Schema::dropIfExists('qb_question_versions');
        Schema::dropIfExists('qb_questions');
    }
};
