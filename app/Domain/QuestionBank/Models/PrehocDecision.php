<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * What a reviewer can decide about a question before it is ever used (blueprint 9). "Retain in
 * QBank" and "Discard" are post-hoc decisions, made after exam data, and are not in this list.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property bool $is_accept
 * @property bool $needs_comment
 * @property bool $is_active
 * @property int $sort_order
 */
final class PrehocDecision extends Model
{
    protected $table = 'qb_prehoc_decisions';

    protected function casts(): array
    {
        return [
            'is_accept' => 'boolean',
            'needs_comment' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
