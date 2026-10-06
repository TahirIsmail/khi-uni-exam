<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The parts a professional result is actually made of.
 *
 * A PMC subject result is not one number. It is the written theory paper, the practical or OSPE,
 * the structured viva, and the internal assessment carried from the year's class work — and the
 * rule is not only 50% overall but 50% in theory and in practical separately, neither making up for
 * the other.
 *
 * This module runs the computer-based paper and nothing else, so the paper becomes one component
 * among several and the rest are entered by the department. Every weight is a row here rather than a
 * constant anywhere, because KMU's blueprint differs from subject to subject and changes without
 * asking us.
 *
 * An examination with no components behaves exactly as it did before this migration: the paper is
 * the result. Nothing already in use changes under anybody.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exm_result_components', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('examination_id')->constrained('exm_examinations')->cascadeOnDelete();
            $table->string('code', 30)->comment('theory, ospe, viva, internal — the department\'s own names');
            $table->string('name', 80);
            $table->decimal('max_marks', 6, 2);
            $table->enum('group', ['theory', 'practical'])
                ->comment('which half of the subject this counts towards, for the separate pass rule');
            $table->decimal('min_pass_percentage', 5, 2)->nullable()
                ->comment('a bar this component\'s group must clear on its own; null means it only adds to the total');
            $table->enum('source', ['cbt', 'entered'])
                ->comment('cbt is this system\'s own paper and is never typed in');
            $table->unsignedSmallInteger('sort_order');
            $table->timestamps();

            $table->unique(['examination_id', 'code'], 'uq_exm_result_components');
            $table->index(['examination_id', 'sort_order']);
        });

        Schema::create('exm_component_marks', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('component_id')->constrained('exm_result_components')->cascadeOnDelete();
            $table->foreignId('candidate_id')->constrained('cand_candidates')->restrictOnDelete();
            $table->decimal('marks', 6, 2);
            $table->unsignedBigInteger('entered_by');
            $table->dateTime('entered_at', 3);
            $table->timestamps();

            // One mark per candidate per component. Correcting it is an update, recorded in the
            // audit chain by App\Domain\Results\Actions\RecordComponentMark — unlike an item mark,
            // which is a sealed examiner decision and never changes.
            $table->unique(['component_id', 'candidate_id'], 'uq_exm_component_marks');
            $table->index(['candidate_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exm_component_marks');
        Schema::dropIfExists('exm_result_components');
    }
};
