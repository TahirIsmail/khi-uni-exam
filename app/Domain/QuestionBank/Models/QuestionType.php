<?php

namespace App\Domain\QuestionBank\Models;

use App\Domain\QuestionBank\Enums\ItemAnswer;
use Illuminate\Database\Eloquent\Model;

/**
 * A kind of question the editor can offer (single best answer, essay, cloze, ...), with what it is
 * made of and which settings it supports. Seeded by migration; not edited at runtime.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $family
 * @property string $description
 * @property bool $has_options
 * @property int $options_min
 * @property int $options_max
 * @property int $correct_min
 * @property int|null $correct_max
 * @property bool $has_items
 * @property int $items_min
 * @property int $items_max
 * @property ItemAnswer $item_answer
 * @property bool $has_accepted_answers
 * @property bool $has_numeric_answer
 * @property bool $is_manually_marked
 * @property bool $requires_confirmation
 * @property bool $supports_shuffle
 * @property bool $supports_partial_credit
 * @property bool $supports_negative_marks
 * @property bool $supports_rubric
 * @property bool $supports_media
 * @property array<string, mixed> $default_settings
 * @property bool $is_active
 * @property int $sort_order
 */
final class QuestionType extends Model
{
    protected $table = 'qb_question_types';

    protected function casts(): array
    {
        return [
            'has_options' => 'boolean',
            'has_items' => 'boolean',
            'item_answer' => ItemAnswer::class,
            'has_accepted_answers' => 'boolean',
            'has_numeric_answer' => 'boolean',
            'is_manually_marked' => 'boolean',
            'requires_confirmation' => 'boolean',
            'supports_shuffle' => 'boolean',
            'supports_partial_credit' => 'boolean',
            'supports_negative_marks' => 'boolean',
            'supports_rubric' => 'boolean',
            'supports_media' => 'boolean',
            'default_settings' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
