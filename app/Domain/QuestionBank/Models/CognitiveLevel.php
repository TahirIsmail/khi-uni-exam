<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A level of thinking a question asks for (recall, application, analysis ...).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_active
 * @property int $sort_order
 */
final class CognitiveLevel extends Model
{
    protected $table = 'qb_cognitive_levels';

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
