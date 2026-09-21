<?php

namespace App\Domain\Blueprint;

use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Models\Examination;
use Illuminate\Support\Facades\DB;

/**
 * A SHA-256 of a blueprint as it stands: the examination it belongs to, its sections, its rows and
 * its mixes, in a fixed order. Taken when the blueprint is approved, so a paper built later can show
 * it was built to exactly this and not to something edited afterwards.
 */
final class BlueprintFingerprint
{
    public function of(Examination $examination, Blueprint $blueprint): string
    {
        $sections = DB::table('exm_sections')->where('examination_id', $examination->id)->orderBy('sort_order')->orderBy('id')->pluck('name', 'id')->all();

        $rows = $blueprint->rows()->get()->map(fn ($row): array => [
            'section' => $row->section_id === null ? null : ($sections[$row->section_id] ?? null),
            'node_id' => $row->node_id,
            'question_type_id' => $row->question_type_id,
            'question_count' => $row->question_count,
            'marks_each' => number_format($row->marks_each, 2, '.', ''),
        ])->sortBy(fn (array $row): string => implode('|', [(string) $row['section'], $row['node_id'], $row['question_type_id'], $row['marks_each']]))->values()->all();

        $targets = $blueprint->targets()->get()->map(fn ($target): array => [
            'dimension' => $target->dimension,
            'level_id' => $target->level_id,
            'percent' => number_format($target->percent, 2, '.', ''),
        ])->sortBy(fn (array $target): string => $target['dimension'].'|'.$target['level_id'])->values()->all();

        return hash('sha256', (string) json_encode([
            'examination' => $examination->id,
            'course_id' => $examination->course_id,
            'exam_type_id' => $examination->exam_type_id,
            'total_marks' => number_format($examination->total_marks, 2, '.', ''),
            'sections' => array_values($sections),
            'rows' => $rows,
            'targets' => $targets,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
