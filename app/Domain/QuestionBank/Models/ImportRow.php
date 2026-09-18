<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of an uploaded spreadsheet: what it said, what it became, and what is wrong with it.
 *
 * @property int $id
 * @property int $import_id
 * @property int $row_number
 * @property array<string, mixed> $raw
 * @property array<string, mixed>|null $parsed
 * @property array<string, list<string>>|null $errors
 * @property list<string>|null $warnings
 * @property string|null $content_hash
 * @property string $status
 * @property int|null $question_id
 * @property int|null $version_id
 */
#[Fillable(['import_id', 'row_number', 'raw', 'parsed', 'errors', 'warnings', 'content_hash', 'status', 'question_id', 'version_id'])]
final class ImportRow extends Model
{
    protected $table = 'qb_import_rows';

    protected function casts(): array
    {
        return [
            'raw' => 'array',
            'parsed' => 'array',
            'errors' => 'array',
            'warnings' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuestionImport, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(QuestionImport::class, 'import_id');
    }
}
