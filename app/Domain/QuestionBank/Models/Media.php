<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A picture or file used in questions, stored once per campus (same checksum = same file).
 *
 * @property int $id
 * @property int $branch_id
 * @property string $disk
 * @property string $path
 * @property string $original_name
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $checksum
 * @property int|null $width
 * @property int|null $height
 * @property string $alt_text
 * @property int $uploaded_by
 */
#[Fillable(['branch_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'checksum', 'width', 'height', 'alt_text', 'uploaded_by'])]
final class Media extends Model
{
    protected $table = 'qb_media';

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
