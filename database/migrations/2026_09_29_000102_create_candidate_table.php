<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The candidate roster for an examination (exam phase, step 17): imported once per examination (the
 * CMS student register is not filled in yet — see exam-phase.md's settled questions), then allocated
 * to a centre and room, checked in on the day and issued a one-time exam PIN.
 *
 * Allocation, check-in and the PIN live on the one row rather than in separate tables, the same way
 * a paper's moderation columns live on exm_papers: a candidate's exam-day state is one small, mostly
 * empty record until each step happens to it, not a family of joins.
 *
 * The PIN is never stored in the clear — only its hash, checked the way a password is — because,
 * with the candidate number, it is what ADR-0003 lets a candidate resume an exam with on another
 * computer.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cand_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('examination_id')->constrained('exm_examinations')->restrictOnDelete();
            $table->unsignedInteger('branch_id')->comment('kmu-cms branches.id');
            $table->string('candidate_no', 30);
            $table->string('name', 150);
            $table->string('roll_no', 60)->nullable();
            $table->string('cnic', 30)->nullable()->comment('or another national/registration number');
            $table->string('email', 190)->nullable();
            $table->string('phone', 30)->nullable();

            $table->string('status', 20)->default('enrolled')->comment('see App\\Domain\\Candidate\\Enums\\CandidateStatus');

            // Allocation to a seat.
            $table->foreignId('centre_id')->nullable()->constrained('cand_centres')->restrictOnDelete();
            $table->foreignId('room_id')->nullable()->constrained('cand_rooms')->restrictOnDelete();
            $table->string('seat_no', 20)->nullable();
            $table->unsignedBigInteger('allocated_by')->nullable();
            $table->dateTime('allocated_at', 3)->nullable();

            // Extra time, granted ahead of the exam day for a recorded reason.
            $table->unsignedSmallInteger('extra_time_minutes')->nullable();
            $table->string('extra_time_reason', 300)->nullable();
            $table->unsignedBigInteger('extra_time_granted_by')->nullable();
            $table->dateTime('extra_time_granted_at', 3)->nullable();

            // Check-in and the PIN, issued on the exam day.
            $table->string('pin_hash', 255)->nullable();
            $table->unsignedBigInteger('pin_issued_by')->nullable();
            $table->dateTime('pin_issued_at', 3)->nullable();
            $table->unsignedBigInteger('checked_in_by')->nullable();
            $table->dateTime('checked_in_at', 3)->nullable();

            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();

            $table->unique(['examination_id', 'candidate_no']);
            $table->index(['examination_id', 'status']);
            $table->index(['room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cand_candidates');
    }
};
