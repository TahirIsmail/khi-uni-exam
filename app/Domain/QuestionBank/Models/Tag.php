<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A free label authors add to questions, kept per campus.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $name
 * @property string $slug
 * @property int $created_by
 */
#[Fillable(['branch_id', 'name', 'slug', 'created_by'])]
final class Tag extends Model
{
    protected $table = 'qb_tags';

    /**
     * @return BelongsToMany<QuestionVersion, $this>
     */
    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(QuestionVersion::class, 'qb_version_tags', 'tag_id', 'version_id');
    }
}
