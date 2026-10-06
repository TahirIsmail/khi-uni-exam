<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Moderating and locking a paper (exam phase, step 4 — see docs/architecture/exam-phase.md).
 *
 *  - The paper's own workflow: Draft -> Submitted -> Approved -> Finalised -> Published, with "sent
 *    back" returning an Approved or Submitted paper to Draft. Only a Draft paper can be changed
 *    (Step 15's actions already enforce this); the database extends the same rule to every row that
 *    belongs to the paper, not only its items.
 *  - Comments the committee leaves while it moderates the paper, open or resolved. A general comment
 *    has no item; one on an item points at it. Finalising is refused while any comment is open.
 *  - Correcting a paper once it is finalised means a new version (StartNewPaperVersion, in the
 *    application layer), never an edit: the old one is kept, exactly as it was finalised.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exm_papers', function (Blueprint $table) {
            $table->unsignedBigInteger('submitted_by')->nullable()->after('blueprint_hash');
            $table->dateTime('submitted_at', 3)->nullable()->after('submitted_by');
            $table->unsignedBigInteger('approved_by')->nullable()->after('submitted_at');
            $table->dateTime('approved_at', 3)->nullable()->after('approved_by');
            $table->unsignedBigInteger('finalised_by')->nullable()->after('approved_at');
            $table->dateTime('finalised_at', 3)->nullable()->after('finalised_by');
            $table->unsignedBigInteger('published_by')->nullable()->after('finalised_at');
            $table->dateTime('published_at', 3)->nullable()->after('published_by');
            $table->char('content_hash', 64)->nullable()->after('published_at')->comment('SHA-256 of the paper as finalised: its items, their marks and order, and how it is presented');
            $table->string('return_reason', 500)->nullable()->after('content_hash')->comment('why the paper was sent back; cleared when it is submitted again');
        });

        Schema::create('exm_paper_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_id')->constrained('exm_papers')->cascadeOnDelete();
            $table->foreignId('item_id')->nullable()->constrained('exm_paper_items')->nullOnDelete();
            $table->string('body', 2000);
            $table->string('status', 20)->default('open')->comment('open, resolved');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at', 3)->nullable();
            $table->timestamps();

            $table->index(['paper_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_paper_comments');

        Schema::table('exm_papers', function (Blueprint $table) {
            $table->dropColumn(['submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'finalised_by', 'finalised_at', 'published_by', 'published_at', 'content_hash', 'return_reason']);
        });
    }
};
