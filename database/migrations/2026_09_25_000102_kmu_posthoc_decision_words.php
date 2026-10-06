<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * KMU's post-hoc report says the decision on a question after an examination in the same five words
 * as the pre-hoc one (KMU requirements, "Item Analysis of each question": "Status/Decision: Accept /
 * Review / Revise / Discard / Retain in Qbank"). The types are brought into line with that list, so
 * a question reads the same before and after it has been used. "Retain in QBank, but watch it" was
 * ours, not KMU's; it is switched off rather than deleted, because it is a lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach ([
            ['code' => 'accept', 'name' => 'Accept', 'description' => 'The statistics are sound; keep using it as it is.', 'keeps' => true, 'sort' => 1],
            ['code' => 'retain', 'name' => 'Retain in QBank', 'description' => 'Keep it in the question bank as it is.', 'keeps' => true, 'sort' => 2],
            ['code' => 'review', 'name' => 'Review', 'description' => 'Look at it again before it is used in another examination.', 'keeps' => true, 'sort' => 3],
            ['code' => 'revise', 'name' => 'Revise', 'description' => 'The statistics show a problem the author should fix.', 'keeps' => true, 'sort' => 4],
            ['code' => 'remove', 'name' => 'Remove / Discard', 'description' => 'Not to be used again.', 'keeps' => false, 'sort' => 5],
        ] as $type) {
            $values = [
                'name' => $type['name'],
                'description' => $type['description'],
                'keeps_question' => $type['keeps'],
                'is_active' => true,
                'sort_order' => $type['sort'],
                'updated_at' => $now,
            ];

            if (DB::table('qb_posthoc_decision_types')->where('code', $type['code'])->exists()) {
                DB::table('qb_posthoc_decision_types')->where('code', $type['code'])->update($values);

                continue;
            }

            DB::table('qb_posthoc_decision_types')->insert([...$values, 'code' => $type['code'], 'created_at' => $now]);
        }

        // "Discard" was our word for the same thing; the row is kept, under KMU's.
        DB::table('qb_posthoc_decision_types')->where('code', 'discard')->update([
            'is_active' => false, 'sort_order' => 9, 'updated_at' => $now,
        ]);
        DB::table('qb_posthoc_decision_types')->where('code', 'retain_watch')->update([
            'is_active' => false, 'sort_order' => 8, 'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        $now = now();

        DB::table('qb_posthoc_decision_types')->whereIn('code', ['accept', 'review', 'remove'])->update([
            'is_active' => false, 'updated_at' => $now,
        ]);
        DB::table('qb_posthoc_decision_types')->whereIn('code', ['discard', 'retain_watch'])->update([
            'is_active' => true, 'updated_at' => $now,
        ]);
    }
};
