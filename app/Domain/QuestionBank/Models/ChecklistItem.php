<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One item-writing rule a reviewer works through (NBME item-writing guide). Configurable in the
 * database: a new rule is a new row, and the required ones must pass before approval.
 *
 * @property int $id
 * @property string $code
 * @property string $text
 * @property string|null $guidance
 * @property string|null $applies_to question type family, null = every type
 * @property bool $is_required
 * @property bool $is_active
 * @property int $sort_order
 */
final class ChecklistItem extends Model
{
    protected $table = 'qb_review_checklist_items';

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
