<?php

namespace App\Domain\Paper\Models;

use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The paper of an examination: the questions chosen from the question bank to match its approved
 * blueprint, and how each candidate meets them.
 *
 * @property int $id
 * @property int $examination_id
 * @property int $version_no
 * @property PaperStatus $status
 * @property bool $shuffle_questions
 * @property bool $shuffle_options
 * @property string|null $blueprint_hash
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable(['examination_id', 'version_no', 'status', 'shuffle_questions', 'shuffle_options', 'blueprint_hash', 'created_by', 'updated_by'])]
final class Paper extends Model
{
    protected $table = 'exm_papers';

    protected function casts(): array
    {
        return [
            'status' => PaperStatus::class,
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Examination, $this>
     */
    public function examination(): BelongsTo
    {
        return $this->belongsTo(Examination::class, 'examination_id');
    }

    /**
     * @return HasMany<PaperItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(PaperItem::class, 'paper_id')->orderBy('position')->orderBy('id');
    }
}
