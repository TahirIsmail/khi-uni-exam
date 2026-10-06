<?php

namespace App\Domain\Paper;

use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\PaperSlot;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The rows of an examination's blueprint, in the order the blueprint gives them, as slots the paper
 * has to fill.
 */
final class PaperSlots
{
    /**
     * @return list<PaperSlot>
     */
    public function of(Examination $examination): array
    {
        return array_values(DB::table('exm_blueprint_rows as r')
            ->join('exm_blueprints as b', 'b.id', '=', 'r.blueprint_id')
            ->leftJoin('exm_sections as s', 's.id', '=', 'r.section_id')
            ->where('b.examination_id', $examination->id)
            ->orderBy('r.sort_order')->orderBy('r.id')
            ->get(['r.node_id', 'r.question_type_id', 'r.marks_each', 'r.question_count', 's.name as section'])
            ->map(fn (stdClass $row): PaperSlot => new PaperSlot(
                (int) $row->node_id,
                (int) $row->question_type_id,
                (float) $row->marks_each,
                $row->section === null ? null : (string) $row->section,
                (int) $row->question_count,
            ))
            ->all());
    }
}
