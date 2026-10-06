<?php

namespace App\Domain\Analytics\Queries;

use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Blueprint\Models\BlueprintRow;
use App\Domain\Blueprint\Models\BlueprintTarget;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\QuestionVersion;

/**
 * How closely the paper actually delivered matches the table of specification it was built to
 * (exam phase, step 22) — every row's planned count and marks against what was drawn, and the
 * planned cognitive/difficulty mix against what the delivered items are actually at.
 */
final class TosCompliance
{
    /**
     * @return array{rows: list<array<string, mixed>>, mixes: list<array<string, mixed>>}
     */
    public function forExamination(Examination $examination): array
    {
        $blueprint = Blueprint::query()->where('examination_id', $examination->id)->first();
        $paper = Paper::query()->where('examination_id', $examination->id)->latest('version_no')->first();
        if ($blueprint === null || $paper === null) {
            return ['rows' => [], 'mixes' => []];
        }

        $paperItems = PaperItem::query()->where('paper_id', $paper->id)->get();
        $rows = BlueprintRow::query()->where('blueprint_id', $blueprint->id)->orderBy('sort_order')->get();

        $rowResults = array_values($rows->map(function (BlueprintRow $row) use ($paperItems): array {
            $delivered = $paperItems->where('row_node_id', $row->node_id)->where('question_type_id', $row->question_type_id);

            return [
                'nodeId' => $row->node_id,
                'questionTypeId' => $row->question_type_id,
                'plannedCount' => $row->question_count,
                'deliveredCount' => $delivered->count(),
                'plannedMarks' => $row->marks(),
                'deliveredMarks' => round($delivered->sum('marks'), 2),
                'compliant' => $delivered->count() === $row->question_count,
            ];
        })->values()->all());

        $versions = QuestionVersion::query()->whereIn('id', $paperItems->pluck('version_id'))->get(['id', 'cognitive_level_id', 'difficulty_level_id'])->keyBy('id');
        $totalMarks = max(0.001, $paperItems->sum('marks'));

        $targets = BlueprintTarget::query()->where('blueprint_id', $blueprint->id)->get();
        $mixResults = array_values($targets->map(function (BlueprintTarget $target) use ($paperItems, $versions, $totalMarks): array {
            $column = $target->dimension === 'cognitive' ? 'cognitive_level_id' : 'difficulty_level_id';
            $deliveredMarks = $paperItems->filter(fn (PaperItem $item): bool => ($versions->get($item->version_id)?->{$column}) === $target->level_id)->sum('marks');
            $deliveredPercent = round(($deliveredMarks / $totalMarks) * 100, 2);

            return [
                'dimension' => $target->dimension,
                'levelId' => $target->level_id,
                'plannedPercent' => $target->percent,
                'deliveredPercent' => $deliveredPercent,
            ];
        })->values()->all());

        return ['rows' => $rowResults, 'mixes' => $mixResults];
    }
}
