<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture placed in a version: on the vignette, stem, an option, a sub-part or the explanation.
 *
 * @property int $id
 * @property int $version_id
 * @property int $media_id
 * @property string $role
 * @property int|null $target_id
 * @property int $sort_order
 */
#[Fillable(['version_id', 'media_id', 'role', 'target_id', 'sort_order'])]
final class VersionMedia extends Model
{
    protected $table = 'qb_version_media';

    /**
     * @return BelongsTo<Media, $this>
     */
    public function media(): BelongsTo
    {
        return $this->belongsTo(Media::class, 'media_id');
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }
}
