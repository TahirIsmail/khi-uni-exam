<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * The university's own wording for the three lists a question is judged by. They asked for:
 *
 *   Cognitive level ....... Recall · Understanding · Application · Analysis
 *   Difficulty ............ Easy · Moderate · Difficult
 *   Question quality ...... Accept · Review · Revise · Remove/Discard · Retain in QBank
 *
 * "Retain in QBank" is a decision taken *after* an examination, so it belongs to the post-hoc list,
 * not to the pre-hoc one (blueprint 9). Evaluation and Synthesis are kept as rows but switched off,
 * because a department may later want the full Bloom scale: turning one back on is one row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Only the four levels KMU uses are offered.
        DB::table('qb_cognitive_levels')->whereIn('code', ['evaluate', 'create'])->update(['is_active' => false]);
        DB::table('qb_cognitive_levels')->where('code', 'recall')->update(['name' => 'Recall']);

        $prehoc = [
            'accept' => ['Accept', 'Good enough to be used in an examination as it is.', true, false, 1, true],
            'review' => ['Review', 'Keep it in the bank but do not use it until the department has looked at it again.', false, true, 2, true],
            'revise' => ['Revise', 'Send it back to the author; it needs another review afterwards.', false, true, 3, true],
            'remove' => ['Remove / Discard', 'Not salvageable; archive it with the reason.', false, true, 4, true],
            // Kept for the questions already decided this way, but no longer offered.
            'accept_minor' => ['Accept with minor changes', 'Usable once the small points in the comments are fixed.', true, true, 5, false],
        ];

        // "Hold for discussion" was this university's "Review".
        DB::table('qb_prehoc_decisions')->where('code', 'hold')->update(['code' => 'review']);

        foreach ($prehoc as $code => [$name, $description, $isAccept, $needsComment, $sort, $active]) {
            DB::table('qb_prehoc_decisions')->where('code', $code)->update([
                'name' => $name,
                'description' => $description,
                'is_accept' => $isAccept,
                'needs_comment' => $needsComment,
                'sort_order' => $sort,
                'is_active' => $active,
                'updated_at' => now(),
            ]);
        }

        DB::table('qb_posthoc_decision_types')->where('code', 'retain')->update(['name' => 'Retain in QBank']);
        DB::table('qb_posthoc_decision_types')->where('code', 'retain_watch')->update(['name' => 'Retain in QBank, but watch it']);
        DB::table('qb_posthoc_decision_types')->where('code', 'discard')->update(['name' => 'Discard']);
    }

    public function down(): void
    {
        DB::table('qb_cognitive_levels')->whereIn('code', ['evaluate', 'create'])->update(['is_active' => true]);
        DB::table('qb_cognitive_levels')->where('code', 'recall')->update(['name' => 'Recall / Remember']);
        DB::table('qb_prehoc_decisions')->where('code', 'review')->update(['code' => 'hold', 'name' => 'Hold for discussion']);
        DB::table('qb_prehoc_decisions')->where('code', 'accept_minor')->update(['is_active' => true]);
        DB::table('qb_prehoc_decisions')->where('code', 'remove')->update(['name' => 'Do not use']);
        DB::table('qb_posthoc_decision_types')->where('code', 'retain')->update(['name' => 'Retain in the question bank']);
    }
};
