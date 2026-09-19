<?php

namespace App\Domain\QuestionBank\Validation;

use App\Domain\QuestionBank\Models\QuestionType;
use App\Support\Html\QuestionHtml;

/**
 * One question's content as the editor sends it, cleaned and ready to store: the text is sanitised
 * here, so nothing unsafe reaches the database or a candidate's screen.
 *
 * @phpstan-type OptionInput array{label: string, body: string, is_correct: bool, weight: float|null, feedback: string|null, sort_order: int, is_position_locked: bool, item_index: int|null}
 * @phpstan-type ItemInput array{body: string, is_true: bool|null, correct_option_label: string|null, marks_fraction: float|null, feedback: string|null, sort_order: int, settings: array<string, mixed>|null}
 * @phpstan-type AnswerInput array{item_index: int|null, match_mode: string, answer_text: string|null, case_sensitive: bool, numeric_value: float|null, tolerance: float|null, tolerance_type: string, unit: string|null, marks_fraction: float, feedback: string|null, sort_order: int}
 * @phpstan-type RubricInput array{criterion: string, max_marks: float, guidance: string|null, sort_order: int}
 * @phpstan-type ReferenceInput array{kind: string, citation: string, locator: string|null, url: string|null, sort_order: int}
 */
final readonly class QuestionContent
{
    /**
     * @param  list<OptionInput>  $options
     * @param  list<ItemInput>  $items
     * @param  list<AnswerInput>  $answers
     * @param  list<RubricInput>  $rubric
     * @param  list<ReferenceInput>  $references
     * @param  list<int>  $tagIds
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public QuestionType $type,
        public int $courseId,
        public int $nodeId,
        public ?string $vignette,
        public string $stem,
        public ?string $leadIn,
        public ?string $explanation,
        public array $settings,
        public float $marks,
        public float $negativeMarks,
        public ?int $disciplineId,
        public ?int $cognitiveLevelId,
        public ?int $difficultyLevelId,
        public array $options = [],
        public array $items = [],
        public array $answers = [],
        public array $rubric = [],
        public array $references = [],
        public array $tagIds = [],
        public ?int $examTypeId = null,
    ) {}

    /**
     * Builds the content from validated request input (shapes already checked by the FormRequest).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, QuestionType $type): self
    {
        $string = static fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        /** @var list<array<string, mixed>> $optionInput */
        $optionInput = array_values((array) ($input['options'] ?? []));
        /** @var list<array<string, mixed>> $itemInput */
        $itemInput = array_values((array) ($input['items'] ?? []));
        /** @var list<array<string, mixed>> $answerInput */
        $answerInput = array_values((array) ($input['answers'] ?? []));
        /** @var list<array<string, mixed>> $rubricInput */
        $rubricInput = array_values((array) ($input['rubric'] ?? []));
        /** @var list<array<string, mixed>> $referenceInput */
        $referenceInput = array_values((array) ($input['references'] ?? []));

        $options = [];
        foreach ($optionInput as $index => $option) {
            $options[] = [
                'label' => (string) ($option['label'] ?? self::letter($index)),
                'body' => (string) QuestionHtml::sanitize((string) ($option['body'] ?? '')),
                'is_correct' => (bool) ($option['is_correct'] ?? false),
                'weight' => isset($option['weight']) ? (float) $option['weight'] : null,
                'feedback' => $string($option['feedback'] ?? null),
                'sort_order' => (int) ($option['sort_order'] ?? $index + 1),
                'is_position_locked' => (bool) ($option['is_position_locked'] ?? false),
                'item_index' => isset($option['item_index']) ? (int) $option['item_index'] : null,
            ];
        }

        $items = [];
        foreach ($itemInput as $index => $item) {
            $items[] = [
                'body' => (string) QuestionHtml::sanitize((string) ($item['body'] ?? '')),
                'is_true' => isset($item['is_true']) ? (bool) $item['is_true'] : null,
                'correct_option_label' => $string($item['correct_option_label'] ?? null),
                'marks_fraction' => isset($item['marks_fraction']) ? (float) $item['marks_fraction'] : null,
                'feedback' => $string($item['feedback'] ?? null),
                'sort_order' => (int) ($item['sort_order'] ?? $index + 1),
                'settings' => isset($item['settings']) && is_array($item['settings']) ? $item['settings'] : null,
            ];
        }

        $answers = [];
        foreach ($answerInput as $index => $answer) {
            $answers[] = [
                'item_index' => isset($answer['item_index']) ? (int) $answer['item_index'] : null,
                'match_mode' => (string) ($answer['match_mode'] ?? 'exact'),
                'answer_text' => $string($answer['answer_text'] ?? null),
                'case_sensitive' => (bool) ($answer['case_sensitive'] ?? false),
                'numeric_value' => isset($answer['numeric_value']) ? (float) $answer['numeric_value'] : null,
                'tolerance' => isset($answer['tolerance']) ? (float) $answer['tolerance'] : null,
                'tolerance_type' => (string) ($answer['tolerance_type'] ?? 'absolute'),
                'unit' => $string($answer['unit'] ?? null),
                'marks_fraction' => (float) ($answer['marks_fraction'] ?? 1),
                'feedback' => $string($answer['feedback'] ?? null),
                'sort_order' => (int) ($answer['sort_order'] ?? $index + 1),
            ];
        }

        $rubric = [];
        foreach ($rubricInput as $index => $criterion) {
            $rubric[] = [
                'criterion' => (string) ($criterion['criterion'] ?? ''),
                'max_marks' => (float) ($criterion['max_marks'] ?? 0),
                'guidance' => $string($criterion['guidance'] ?? null),
                'sort_order' => (int) ($criterion['sort_order'] ?? $index + 1),
            ];
        }

        $references = [];
        foreach ($referenceInput as $index => $reference) {
            $references[] = [
                'kind' => (string) ($reference['kind'] ?? 'book'),
                'citation' => (string) ($reference['citation'] ?? ''),
                'locator' => $string($reference['locator'] ?? null),
                'url' => $string($reference['url'] ?? null),
                'sort_order' => (int) ($reference['sort_order'] ?? $index + 1),
            ];
        }

        /** @var array<string, mixed> $settings */
        $settings = is_array($input['settings'] ?? null) ? $input['settings'] : [];

        return new self(
            type: $type,
            courseId: (int) $input['course_id'],
            nodeId: (int) $input['node_id'],
            vignette: QuestionHtml::sanitize(is_string($input['vignette'] ?? null) ? $input['vignette'] : null),
            stem: (string) QuestionHtml::sanitize((string) ($input['stem'] ?? '')),
            leadIn: $string($input['lead_in'] ?? null),
            explanation: QuestionHtml::sanitize(is_string($input['explanation'] ?? null) ? $input['explanation'] : null),
            settings: array_replace($type->default_settings, $settings),
            marks: (float) ($input['marks'] ?? 1),
            negativeMarks: (float) ($input['negative_marks'] ?? 0),
            disciplineId: isset($input['discipline_id']) ? (int) $input['discipline_id'] : null,
            cognitiveLevelId: isset($input['cognitive_level_id']) ? (int) $input['cognitive_level_id'] : null,
            difficultyLevelId: isset($input['difficulty_level_id']) ? (int) $input['difficulty_level_id'] : null,
            examTypeId: isset($input['exam_type_id']) ? (int) $input['exam_type_id'] : null,
            options: $options,
            items: $items,
            answers: $answers,
            rubric: $rubric,
            references: $references,
            tagIds: array_values(array_map('intval', (array) ($input['tag_ids'] ?? []))),
        );
    }

    /**
     * @return list<string>
     */
    public function optionBodies(): array
    {
        return array_map(fn (array $option): string => (string) $option['body'], $this->options);
    }

    public function contentHash(): string
    {
        return QuestionHtml::contentHash($this->stem, $this->optionBodies());
    }

    /** Plain text of everything a search should find. */
    public function searchText(): string
    {
        $parts = [$this->vignette, $this->stem, $this->leadIn, $this->explanation];
        foreach ($this->options as $option) {
            $parts[] = $option['body'];
        }
        foreach ($this->items as $item) {
            $parts[] = $item['body'];
        }
        foreach ($this->answers as $answer) {
            $parts[] = $answer['answer_text'];
        }

        return mb_substr(trim(implode(' ', array_map(fn (?string $part): string => QuestionHtml::toText($part), $parts))), 0, 60000);
    }

    public static function letter(int $index): string
    {
        return chr(65 + ($index % 26));
    }
}
