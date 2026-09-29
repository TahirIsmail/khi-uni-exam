<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * A computer's mark on typed text is a suggestion, not a verdict.
 *
 * Short answers and cloze blanks are scored by matching what the candidate typed against a list of
 * accepted answers — exact match, "contains", or a regular expression. That is fine for a fixed
 * value and wrong for a medical answer, where spelling, word order and abbreviations all vary and a
 * correct answer is marked zero for none of the reasons that matter.
 *
 * So the mark is still computed on submission, but it no longer counts until an examiner confirms
 * it: the examiner opens a screen already filled in rather than a blank one, and says yes or gives
 * a different mark. Until they do, App\Domain\Marking\Queries\FinalMark treats the item as unmarked
 * and the result stays pending.
 *
 * Which types need confirming is a column rather than a list in code, so the university can change
 * its mind about a type without waiting for a release.
 */
return new class extends Migration
{
    private const REQUIRES_CONFIRMATION = ['short_answer', 'cloze'];

    public function up(): void
    {
        Schema::table('qb_question_types', function (Blueprint $table): void {
            $table->boolean('requires_confirmation')->default(false)->after('is_manually_marked')
                ->comment('auto-marked, but an examiner must confirm the mark before it counts');
        });

        DB::table('qb_question_types')->whereIn('code', self::REQUIRES_CONFIRMATION)
            ->update(['requires_confirmation' => true]);

        // An ALTER does not disturb trg_mrk_item_marks_no_update: that trigger is per row, and no
        // row is written here.
        Schema::table('mrk_item_marks', function (Blueprint $table): void {
            $table->boolean('is_provisional')->default(false)->after('marks_awarded')
                ->comment('an auto mark awaiting an examiner\'s confirmation; never final on its own');
        });
    }

    public function down(): void
    {
        Schema::table('mrk_item_marks', function (Blueprint $table): void {
            $table->dropColumn('is_provisional');
        });

        Schema::table('qb_question_types', function (Blueprint $table): void {
            $table->dropColumn('requires_confirmation');
        });
    }
};
