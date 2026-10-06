<?php

namespace App\Domain\Paper\Models;

use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Enums\PaperStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property int|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $finalised_by
 * @property Carbon|null $finalised_at
 * @property int|null $published_by
 * @property Carbon|null $published_at
 * @property string|null $content_hash
 * @property string|null $return_reason
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable(['examination_id', 'version_no', 'status', 'shuffle_questions', 'shuffle_options', 'blueprint_hash', 'submitted_by', 'submitted_at', 'approved_by', 'approved_at', 'finalised_by', 'finalised_at', 'published_by', 'published_at', 'content_hash', 'return_reason', 'created_by', 'updated_by'])]
final class Paper extends Model
{
    protected $table = 'exm_papers';

    protected function casts(): array
    {
        return [
            'status' => PaperStatus::class,
            'shuffle_questions' => 'boolean',
            'shuffle_options' => 'boolean',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'finalised_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<PaperComment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(PaperComment::class, 'paper_id')->orderBy('id');
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
