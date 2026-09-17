<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A difficulty band with the expected-score cut points the committee uses.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property float|null $expected_score_from
 * @property float|null $expected_score_to
 * @property bool $is_active
 * @property int $sort_order
 */
final class DifficultyLevel extends Model
{
    protected $table = 'qb_difficulty_levels';

    protected function casts(): array
    {
        return [
            'expected_score_from' => 'float',
            'expected_score_to' => 'float',
            'is_active' => 'boolean',
        ];
    }
}
