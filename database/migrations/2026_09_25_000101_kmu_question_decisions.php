<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * KMU's pre-hoc assessment of a question is three things (KMU requirements, "Pre-Hoc Assessment
 * Categories"): its cognitive level, its difficulty level, and its quality decision — Accept,
 * Review, Revise, Remove/Discard or Retain in QBank. All five are offered to reviewers and to the
 * approving authority, and each has its own outcome:
 *
 *   Accept, Retain in QBank .. approved and stored in the QBank
 *   Review ................... reviewed again, in a new round of review
 *   Revise ................... back to the author to change it
 *   Remove / Discard ......... archived with the reason
 *
 * The decision taken on a version is kept on it (`decision_code`), so its status reads the way KMU
 * says it: "Retain in QBank" rather than "Accept" when that was the decision. Who decided what, and
 * when, stays in the pre-hoc assessments and the status log.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('qb_prehoc_decisions')->insertOrIgnore([
            'code' => 'retain',
            'name' => 'Retain in QBank',
            'description' => 'Keep it in the question bank as it is.',
            'is_accept' => true,
            'needs_comment' => false,
            'is_active' => true,
            'sort_order' => 2,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // KMU's order: Accept, Retain in QBank, Review, Revise, Remove / Discard.
        foreach (['accept' => 1, 'retain' => 2, 'review' => 3, 'revise' => 4, 'remove' => 5, 'accept_minor' => 9] as $code => $sort) {
            DB::table('qb_prehoc_decisions')->where('code', $code)->update(['sort_order' => $sort, 'updated_at' => $now]);
        }
        DB::table('qb_prehoc_decisions')->where('code', 'review')->update([
            'description' => 'Look at it again: it goes through another round of review.',
        ]);
        DB::table('qb_prehoc_decisions')->where('code', 'revise')->update([
            'description' => 'Send it back to the author to change it.',
        ]);

        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->string('decision_code', 20)->nullable()->after('status')->comment('the latest KMU decision on this version: accept, retain, review, revise, remove');
        });
    }

    public function down(): void
    {
        Schema::table('qb_question_versions', function (Blueprint $table) {
            $table->dropColumn('decision_code');
        });
        DB::table('qb_prehoc_decisions')->where('code', 'retain')->delete();
    }
};
