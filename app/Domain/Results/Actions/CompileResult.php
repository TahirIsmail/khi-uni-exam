<?php

namespace App\Domain\Results\Actions;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\Marking\Queries\FinalMark;
use App\Domain\Results\Models\Result;
use Illuminate\Support\Facades\DB;

/**
 * Compiles one attempt's result from mrk_item_marks (exam phase, step 21): raw marks, negative
 * marking (only against a wrongly *answered* auto-marked item, never an essay or a blank), and
 * whether it passes. A live cache, recomputed every time this runs — never a fact of its own.
 */
final class CompileResult
{
    public function __invoke(CandidateExam $attempt): Result
    {
        $examination = $attempt->examination;
        $items = $attempt->items()->with('paperItem')->get();

        $marks = ItemMark::query()->whereIn('cand_paper_item_id', $items->pluck('id'))->get()
            ->groupBy('cand_paper_item_id');

        $answers = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)
            ->get(['cand_paper_item_id', 'payload'])->keyBy('cand_paper_item_id');

        $rawMarks = 0.0;
        $deduction = 0.0;
        $pending = false;

        foreach ($items as $item) {
            $itemMarks = $marks->get($item->id, collect());
            $final = FinalMark::of($itemMarks, $examination->require_double_marking);

            if ($final === null) {
                $pending = true;

                continue;
            }

            $rawMarks += $final->marks_awarded;

            if ($examination->negative_marking && $final->source === MarkSource::Auto && $final->marks_awarded == 0.0) {
                $row = $answers->get($item->id);
                $payload = $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);
                if ($payload !== []) {
                    $deduction += (float) $examination->negative_fraction * $final->max_marks;
                }
            }
        }

        $totalMarks = $pending ? 0.0 : max(0.0, $rawMarks - $deduction);
        $percentage = ($pending || $examination->total_marks <= 0) ? 0.0 : ($totalMarks / $examination->total_marks) * 100;

        return Result::query()->updateOrCreate(
            ['candidate_exam_id' => $attempt->id],
            [
                'raw_marks' => round($rawMarks, 2),
                'negative_deduction' => round($deduction, 2),
                'total_marks' => round($totalMarks, 2),
                'percentage' => round($percentage, 2),
                'is_pass' => ! $pending && $percentage >= $examination->pass_percentage,
                'pending_items' => $pending,
                'compiled_at' => now(),
            ],
        );
    }
}
