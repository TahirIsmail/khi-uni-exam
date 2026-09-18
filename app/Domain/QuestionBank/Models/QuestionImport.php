<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One uploaded spreadsheet of questions: what was read, what is wrong with it, and whether it has
 * been committed into the bank.
 *
 * @property int $id
 * @property int $branch_id
 * @property string $original_name
 * @property string $disk
 * @property string $path
 * @property string $format
 * @property int $size_bytes
 * @property array<string, mixed>|null $defaults
 * @property int $rows_total
 * @property int $rows_valid
 * @property int $rows_invalid
 * @property int $rows_committed
 * @property string $status
 * @property int $uploaded_by
 * @property Carbon|null $committed_at
 * @property int|null $committed_by
 */
#[Fillable(['branch_id', 'original_name', 'disk', 'path', 'format', 'size_bytes', 'defaults', 'rows_total', 'rows_valid', 'rows_invalid', 'rows_committed', 'status', 'uploaded_by', 'committed_at', 'committed_by'])]
final class QuestionImport extends Model
{
    protected $table = 'qb_imports';

    protected function casts(): array
    {
        return [
            'defaults' => 'array',
            'committed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<ImportRow, $this>
     */
    public function rows(): HasMany
    {
        return $this->hasMany(ImportRow::class, 'import_id')->orderBy('row_number');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isCommittable(): bool
    {
        return $this->status === 'checked' && $this->rows_valid > 0;
    }
}
