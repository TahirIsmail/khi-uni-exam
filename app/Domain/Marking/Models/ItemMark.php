<?php

namespace App\Domain\Marking\Models;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Enums\MarkSource;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One mark for one item of one attempt, from one source — a fact, never changed once written
 * (migration 2026_10_01_000103).
 *
 * @property int $id
 * @property int $candidate_exam_id
 * @property int $cand_paper_item_id
 * @property MarkSource $source
 * @property float $marks_awarded
 * @property bool $is_provisional
 * @property float $max_marks
 * @property int|null $marked_by
 * @property string|null $comments
 * @property Carbon $marked_at
 */
#[Fillable(['candidate_exam_id', 'cand_paper_item_id', 'source', 'marks_awarded', 'is_provisional', 'max_marks', 'marked_by', 'comments', 'marked_at'])]
final class ItemMark extends Model
{
    protected $table = 'mrk_item_marks';

    protected function casts(): array
    {
        return [
            'source' => MarkSource::class,
            'marks_awarded' => 'float',
            'is_provisional' => 'boolean',
            'max_marks' => 'float',
            'marked_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function candidateExam(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'candidate_exam_id');
    }

    /**
     * @return BelongsTo<CandidatePaperItem, $this>
     */
    public function candidatePaperItem(): BelongsTo
    {
        return $this->belongsTo(CandidatePaperItem::class, 'cand_paper_item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by');
    }

    /**
     * @return HasMany<ItemMarkCriterion, $this>
     */
    public function criteria(): HasMany
    {
        return $this->hasMany(ItemMarkCriterion::class, 'item_mark_id');
    }
}
