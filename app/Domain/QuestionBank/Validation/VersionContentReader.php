<?php

namespace App\Domain\QuestionBank\Validation;

use App\Domain\QuestionBank\Models\QuestionAnswer;
use App\Domain\QuestionBank\Models\QuestionItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionReference;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\RubricCriterion;

/**
 * Reads a stored version back into the shape the validator and the editor use, so the rules are
 * applied to what is in the database, not to what a form claimed.
 */
final class VersionContentReader
{
    public function read(QuestionVersion $version): QuestionContent
    {
        $version->loadMissing(['type', 'options', 'items', 'answers', 'rubricCriteria', 'references', 'tags']);
        $itemIndexById = [];
        foreach ($version->items as $index => $item) {
            $itemIndexById[$item->id] = $index;
        }

        return new QuestionContent(
            type: $version->type,
            courseId: $version->course_id,
            nodeId: $version->node_id,
            vignette: $version->vignette,
            stem: $version->stem,
            leadIn: $version->lead_in,
            explanation: $version->explanation,
            settings: $version->settings ?? [],
            marks: $version->marks,
            negativeMarks: $version->negative_marks,
            disciplineId: $version->discipline_id,
            cognitiveLevelId: $version->cognitive_level_id,
            difficultyLevelId: $version->difficulty_level_id,
            options: array_values($version->options->map(fn (QuestionOption $option): array => [
                'label' => $option->label,
                'body' => $option->body,
                'is_correct' => $option->is_correct,
                'weight' => $option->weight,
                'feedback' => $option->feedback,
                'sort_order' => $option->sort_order,
                'is_position_locked' => $option->is_position_locked,
                'item_index' => $option->item_id !== null ? ($itemIndexById[$option->item_id] ?? null) : null,
            ])->values()->all()),
            items: array_values($version->items->map(fn (QuestionItem $item): array => [
                'body' => $item->body,
                'is_true' => $item->is_true,
                'correct_option_label' => $item->correct_option_id !== null
                    ? $version->options->firstWhere('id', $item->correct_option_id)?->label
                    : null,
                'marks_fraction' => $item->marks_fraction,
                'feedback' => $item->feedback,
                'sort_order' => $item->sort_order,
                'settings' => $item->settings,
            ])->values()->all()),
            answers: array_values($version->answers->map(fn (QuestionAnswer $answer): array => [
                'item_index' => $answer->item_id !== null ? ($itemIndexById[$answer->item_id] ?? null) : null,
                'match_mode' => $answer->match_mode,
                'answer_text' => $answer->answer_text,
                'case_sensitive' => $answer->case_sensitive,
                'numeric_value' => $answer->numeric_value,
                'tolerance' => $answer->tolerance,
                'tolerance_type' => $answer->tolerance_type,
                'unit' => $answer->unit,
                'marks_fraction' => $answer->marks_fraction,
                'feedback' => $answer->feedback,
                'sort_order' => $answer->sort_order,
            ])->values()->all()),
            rubric: array_values($version->rubricCriteria->map(fn (RubricCriterion $criterion): array => [
                'criterion' => $criterion->criterion,
                'max_marks' => $criterion->max_marks,
                'guidance' => $criterion->guidance,
                'sort_order' => $criterion->sort_order,
            ])->values()->all()),
            references: array_values($version->references->map(fn (QuestionReference $reference): array => [
                'kind' => $reference->kind,
                'citation' => $reference->citation,
                'locator' => $reference->locator,
                'url' => $reference->url,
                'sort_order' => $reference->sort_order,
            ])->values()->all()),
            tagIds: array_values($version->tags->pluck('id')->map(fn (mixed $id): int => (int) $id)->all()),
        );
    }
}
