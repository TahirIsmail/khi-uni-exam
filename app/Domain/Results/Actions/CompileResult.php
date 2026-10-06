<?php

namespace App\Domain\Results\Actions;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\Marking\Queries\FinalMark;
use App\Domain\Results\Models\Result;
use App\Domain\Results\Support\GradeScales;
use App\Support\Cms\CmsAcademic;
use Illuminate\Support\Facades\DB;

/**
 * Compiles one attempt's result from mrk_item_marks (exam phase, step 21): raw marks, negative
 * marking (only against a wrongly *answered* auto-marked item, never an essay or a blank), and
 * whether it passes. A live cache, recomputed every time this runs — never a fact of its own.
 */
final class CompileResult
{
    public function __construct(
        private readonly CmsAcademic $academic,
        private readonly GradeScales $scales,
    ) {}

    public function __invoke(CandidateExam $attempt): Result
    {
        // Callers reach this with attempts fetched several different ways, so the examination is
        // loaded here rather than relied on being eager-loaded by each of them.
        $attempt->loadMissing('examination');

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

        // A grade is only meaningful once everything is marked; a half-marked attempt gets none.
        $awarded = $pending ? null : $this->grade($examination->programme_id, $percentage);

        return Result::query()->updateOrCreate(
            ['candidate_exam_id' => $attempt->id],
            [
                'raw_marks' => round($rawMarks, 2),
                'negative_deduction' => round($deduction, 2),
                'total_marks' => round($totalMarks, 2),
                'percentage' => round($percentage, 2),
                'grade' => $awarded['grade'] ?? null,
                'grade_point' => $awarded['point'] ?? null,
                'grade_remark' => $awarded['remark'] ?? null,
                'is_pass' => ! $pending && $percentage >= $examination->pass_percentage,
                'pending_items' => $pending,
                'compiled_at' => now(),
            ],
        );
    }

    /**
     * Which scale applies is the programme's own business: annual programmes are graded out of
     * marks, semester ones on the 4.00 scale.
     *
     * @return array{grade: string, point: ?float, remark: string}|null
     */
    private function grade(?int $programmeId, float $percentage): ?array
    {
        if ($programmeId === null) {
            return null;
        }

        $calendar = $this->academic->programmeCalendar($programmeId);

        return $calendar === null ? null : $this->scales->award($calendar, $percentage);
    }
}
