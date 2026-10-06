<?php

namespace App\Http\Requests\Exam;

use App\Domain\Blueprint\BlueprintInput;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The shape of what the blueprint screen sends. That the topics belong to the examination's course,
 * the types are in use and the mixes add up is checked by the action, with the campus and rights.
 */
class SaveBlueprintRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $maxCount = (int) config('exam.blueprint.max_count_per_row');
        $maxRows = (int) config('exam.blueprint.max_rows');

        return [
            'sections' => ['array', 'max:'.(int) config('exam.blueprint.max_sections')],
            'sections.*' => ['required', 'string', 'max:100'],

            'rows' => ['array', 'max:'.$maxRows],
            'rows.*.section' => ['nullable', 'integer', 'min:0', 'max:50'],
            // 0: the whole course (every question of it, whatever subject or topic, and those filed on the course itself).
            'rows.*.node_id' => ['required', 'integer', 'min:0', 'max:4294967295'],
            'rows.*.question_type_id' => ['required', 'integer', 'min:1', 'max:255'],
            'rows.*.question_count' => ['required', 'integer', 'min:1', 'max:'.$maxCount],
            'rows.*.marks_each' => ['required', 'numeric', 'gt:0', 'max:'.(float) config('qbank.marks.max')],

            'cognitive' => ['array', 'max:20'],
            'cognitive.*.level_id' => ['required', 'integer', 'min:1', 'max:255'],
            'cognitive.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
            'difficulty' => ['array', 'max:20'],
            'difficulty.*.level_id' => ['required', 'integer', 'min:1', 'max:255'],
            'difficulty.*.percent' => ['required', 'numeric', 'min:0', 'max:100'],
        ];
    }

    public function blueprintInput(): BlueprintInput
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        $targets = fn (string $key): array => array_values(array_map(
            fn (array $target): array => ['level_id' => (int) $target['level_id'], 'percent' => round((float) $target['percent'], 2)],
            (array) ($data[$key] ?? []),
        ));

        return new BlueprintInput(
            sections: array_values(array_map(fn (mixed $name): string => trim((string) $name), (array) ($data['sections'] ?? []))),
            rows: array_values(array_map(fn (array $row): array => [
                'section' => isset($row['section']) ? (int) $row['section'] : null,
                'node_id' => (int) $row['node_id'],
                'question_type_id' => (int) $row['question_type_id'],
                'question_count' => (int) $row['question_count'],
                'marks_each' => round((float) $row['marks_each'], 2),
            ], (array) ($data['rows'] ?? []))),
            cognitive: $targets('cognitive'),
            difficulty: $targets('difficulty'),
        );
    }
}
