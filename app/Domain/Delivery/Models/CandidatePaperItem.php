<?php

namespace App\Domain\Delivery\Models;

use App\Domain\Paper\Models\PaperItem;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question of a candidate's paper, in their own order — fixed the moment the attempt starts
 * (migration 2026_09_30_000102).
 *
 * @property int $id
 * @property int $candidate_exam_id
 * @property int $paper_item_id
 * @property int $position
 * @property list<int>|null $option_order
 */
#[Fillable(['candidate_exam_id', 'paper_item_id', 'position', 'option_order'])]
final class CandidatePaperItem extends Model
{
    protected $table = 'cand_paper_items';

    protected function casts(): array
    {
        return ['option_order' => 'array'];
    }

    /**
     * @return BelongsTo<PaperItem, $this>
     */
    public function paperItem(): BelongsTo
    {
        return $this->belongsTo(PaperItem::class, 'paper_item_id');
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function candidateExam(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'candidate_exam_id');
    }
}
