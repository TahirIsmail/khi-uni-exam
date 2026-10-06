<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One of the four outcomes a department may decide about a question once there are exam statistics
 * for it (exam phase, step 22): retain, retain but watch it, send back for revision, or discard.
 * Seeded by migration 2026_09_22_000101; not edited at runtime.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $keeps_question
 * @property bool $is_active
 * @property int $sort_order
 */
final class PosthocDecisionType extends Model
{
    protected $table = 'qb_posthoc_decision_types';

    protected function casts(): array
    {
        return [
            'keeps_question' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
