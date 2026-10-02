<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The reviewers an author asks for when writing a question (KMU): one for the department / subject
 * review and one for the QBank / academic review. Empty means "whoever is least busy", as before.
 *
 * They are a wish kept with the version, not an assignment: the review is given to that person when
 * the question reaches their level (AssignReviewers::auto), and only while they may still review it;
 * otherwise it goes to the least busy reviewer. Not part of the frozen content.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->foreignId('subject_reviewer_id')->nullable()->after('author_id')->comment('asked for the department / subject review')->constrained('users')->nullOnDelete();
            $table->foreignId('academic_reviewer_id')->nullable()->after('subject_reviewer_id')->comment('asked for the QBank / academic review')->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('academic_reviewer_id');
            $table->dropConstrainedForeignId('subject_reviewer_id');
        });
    }
};
