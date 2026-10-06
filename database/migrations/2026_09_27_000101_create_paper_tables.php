<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The paper (exam phase, step 3 — see docs/architecture/exam-phase.md): the questions an examination
 * is built from, chosen from the question bank to match its approved blueprint.
 *
 *  - A paper belongs to an examination and has a version number, so that a paper which has been
 *    finalised can be replaced by a new version rather than edited (step 16 adds the steps after
 *    "draft").
 *  - Each item is a question *version*, pinned when it is chosen: whatever happens to the question in
 *    the bank afterwards, the paper keeps asking exactly what was chosen.
 *  - An item records the blueprint row it was chosen for — its topic, type, marks and section — not
 *    the row's id. Rows are replaced when a blueprint is saved, and the same topic, type, marks and
 *    section is what makes a row the same row, so items find their row again by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_papers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained('exm_examinations')->cascadeOnDelete();
            $table->unsignedSmallInteger('version_no')->default(1);
            $table->string('status', 20)->default('draft')->comment('see App\\Domain\\Paper\\Enums\\PaperStatus');

            // How each candidate meets it: fixed, or in an order of their own. Different for every
            // candidate is the secure default; a paper that has to be read in one order turns it off.
            $table->boolean('shuffle_questions')->default(true);
            $table->boolean('shuffle_options')->default(true);

            $table->char('blueprint_hash', 64)->nullable()->comment('the fingerprint of the blueprint the paper was last drawn against');

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['examination_id', 'version_no']);
        });

        Schema::create('exm_paper_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_id')->constrained('exm_papers')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->foreignId('question_id')->constrained('qb_questions')->restrictOnDelete();
            $table->foreignId('version_id')->constrained('qb_question_versions')->restrictOnDelete();
            $table->unsignedTinyInteger('question_type_id');

            // The blueprint row the item was chosen for.
            $table->unsignedInteger('row_node_id')->comment('the topic (or heading) of the row; the question may sit below it');
            $table->string('section_name', 100)->nullable();
            $table->decimal('marks', 6, 2);

            $table->boolean('is_locked')->default(false)->comment('kept when the rest of the paper is drawn again');
            $table->enum('source', ['auto', 'manual']);
            $table->foreignId('picked_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['paper_id', 'question_id']);
            $table->index(['paper_id', 'position']);
            $table->foreign('question_type_id')->references('id')->on('qb_question_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_paper_items');
        Schema::dropIfExists('exm_papers');
    }
};
