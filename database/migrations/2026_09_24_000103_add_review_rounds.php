<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Each time a version is sent for review a new round of review starts, and only the reviews of that
 * round count: a review from before the author changed the question is history, not a vote on what
 * is there now. The round was told apart by time before, which fails when two steps fall in the same
 * second; it is now a number the version carries and every assignment and review records.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->unsignedSmallInteger('review_round')->default(0)->after('submitted_at')->comment('how many times it has been sent for review');
        });
        Schema::table('qb_review_assignments', function (Blueprint $table) {
            $table->unsignedSmallInteger('round')->default(1)->after('stage');
            $table->index(['version_id', 'round']);
        });
        Schema::table('qb_reviews', function (Blueprint $table) {
            $table->unsignedSmallInteger('round')->default(1)->after('stage');
        });

        // Anything already in review belongs to its first round.
        DB::table('qb_question_versions')->whereNotNull('submitted_at')->update(['review_round' => 1]);
    }

    public function down(): void
    {
        Schema::table('qb_reviews', function (Blueprint $table) {
            $table->dropColumn('round');
        });
        Schema::table('qb_review_assignments', function (Blueprint $table) {
            $table->dropIndex(['version_id', 'round']);
            $table->dropColumn('round');
        });
        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->dropColumn('review_round');
        });
    }
};
