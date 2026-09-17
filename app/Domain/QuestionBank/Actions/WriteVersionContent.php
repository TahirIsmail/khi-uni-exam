<?php

namespace App\Domain\QuestionBank\Actions;

use App\Domain\QuestionBank\Models\QuestionAnswer;
use App\Domain\QuestionBank\Models\QuestionItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionReference;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\RubricCriterion;
use App\Domain\QuestionBank\Validation\QuestionContent;

/**
 * Writes the parts of a draft version: options, sub-parts, accepted answers, rubric, references and
 * tags. They are replaced as a set, because the editor always sends the whole question.
 *
 * Only drafts get here; the database refuses anything else.
 */
final class WriteVersionContent
{
    public function __invoke(QuestionVersion $version, QuestionContent $content): void
    {
        // Options first: sub-parts point at them, and answers point at sub-parts.
        $version->answers()->delete();
        QuestionItem::query()->where('version_id', $version->id)->update(['correct_option_id' => null]);
        $version->options()->whereNotNull('item_id')->delete();
        $version->items()->delete();
        $version->options()->delete();
        $version->rubricCriteria()->delete();
        $version->references()->delete();

        $optionIdByLabel = [];
        foreach ($content->options as $option) {
            if ($option['item_index'] !== null) {
                continue;
            }
            $saved = QuestionOption::query()->create([
                'version_id' => $version->id,
                'label' => $option['label'],
                'body' => $option['body'],
                'is_correct' => $option['is_correct'],
                'weight' => $option['weight'],
                'feedback' => $option['feedback'],
                'sort_order' => $option['sort_order'],
                'is_position_locked' => $option['is_position_locked'],
            ]);
            $optionIdByLabel[$option['label']] = $saved->id;
        }

        $itemIds = [];
        foreach ($content->items as $index => $item) {
            $saved = QuestionItem::query()->create([
                'version_id' => $version->id,
                'sort_order' => $item['sort_order'],
                'body' => $item['body'],
                'is_true' => $item['is_true'],
                'correct_option_id' => $item['correct_option_label'] !== null ? ($optionIdByLabel[$item['correct_option_label']] ?? null) : null,
                'marks_fraction' => $item['marks_fraction'],
                'feedback' => $item['feedback'],
                'settings' => $item['settings'],
            ]);
            $itemIds[$index] = $saved->id;
        }

        // Options that belong to one sub-part only (for example the word list of a single blank).
        foreach ($content->options as $option) {
            if ($option['item_index'] === null) {
                continue;
            }
            QuestionOption::query()->create([
                'version_id' => $version->id,
                'item_id' => $itemIds[$option['item_index']] ?? null,
                'label' => $option['label'],
                'body' => $option['body'],
                'is_correct' => $option['is_correct'],
                'weight' => $option['weight'],
                'feedback' => $option['feedback'],
                'sort_order' => $option['sort_order'],
                'is_position_locked' => $option['is_position_locked'],
            ]);
        }

        foreach ($content->answers as $answer) {
            QuestionAnswer::query()->create([
                'version_id' => $version->id,
                'item_id' => $answer['item_index'] !== null ? ($itemIds[$answer['item_index']] ?? null) : null,
                'match_mode' => $answer['match_mode'],
                'answer_text' => $answer['answer_text'],
                'case_sensitive' => $answer['case_sensitive'],
                'numeric_value' => $answer['numeric_value'],
                'tolerance' => $answer['tolerance'],
                'tolerance_type' => $answer['tolerance_type'],
                'unit' => $answer['unit'],
                'marks_fraction' => $answer['marks_fraction'],
                'feedback' => $answer['feedback'],
                'sort_order' => $answer['sort_order'],
            ]);
        }

        foreach ($content->rubric as $criterion) {
            RubricCriterion::query()->create([
                'version_id' => $version->id,
                'sort_order' => $criterion['sort_order'],
                'criterion' => $criterion['criterion'],
                'max_marks' => $criterion['max_marks'],
                'guidance' => $criterion['guidance'],
            ]);
        }

        foreach ($content->references as $reference) {
            QuestionReference::query()->create([
                'version_id' => $version->id,
                'kind' => $reference['kind'],
                'citation' => $reference['citation'],
                'locator' => $reference['locator'],
                'url' => $reference['url'],
                'sort_order' => $reference['sort_order'],
            ]);
        }

        $version->tags()->sync($content->tagIds);
    }
}
