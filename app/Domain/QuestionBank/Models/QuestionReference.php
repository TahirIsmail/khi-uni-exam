<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where the answer comes from: a book, journal article, guideline or link.
 *
 * @property int $id
 * @property int $version_id
 * @property string $kind
 * @property string $citation
 * @property string|null $locator
 * @property string|null $url
 * @property int $sort_order
 */
#[Fillable(['version_id', 'kind', 'citation', 'locator', 'url', 'sort_order'])]
final class QuestionReference extends Model
{
    protected $table = 'qb_references';

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }
}
