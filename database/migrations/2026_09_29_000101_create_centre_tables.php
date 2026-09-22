<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Exam centres and their rooms (exam phase, step 17 — see docs/architecture/exam-phase.md). Campus
 * infrastructure, reused across every examination held there — not tied to any one course, so it is
 * managed by campus alone (`centre.manage`), not by the course-scoped exam access blueprints and
 * papers already check.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cand_centres', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('branch_id')->comment('kmu-cms branches.id');
            $table->string('name', 150);
            $table->string('code', 30);
            $table->string('address', 300)->nullable();
            $table->boolean('is_active')->default(true);

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'code']);
            $table->index(['branch_id', 'is_active']);
        });

        Schema::create('cand_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('centre_id')->constrained('cand_centres')->cascadeOnDelete();
            $table->string('name', 100);
            $table->unsignedSmallInteger('capacity');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['centre_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cand_rooms');
        Schema::dropIfExists('cand_centres');
    }
};
