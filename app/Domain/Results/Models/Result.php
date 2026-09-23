<?php

namespace App\Domain\Results\Models;

use App\Domain\Delivery\Models\CandidateExam;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One attempt's compiled result — a cache of what mrk_item_marks already says, recomputed in
 * place by App\Domain\Results\Actions\CompileResult, never a fact of its own.
 *
 * @property int $id
 * @property int $candidate_exam_id
 * @property float $raw_marks
 * @property float $negative_deduction
 * @property float $total_marks
 * @property float $percentage
 * @property bool $is_pass
 * @property bool $pending_items
 * @property Carbon $compiled_at
 */
#[Fillable(['candidate_exam_id', 'raw_marks', 'negative_deduction', 'total_marks', 'percentage', 'is_pass', 'pending_items', 'compiled_at'])]
final class Result extends Model
{
    protected $table = 'exm_results';

    protected function casts(): array
    {
        return [
            'raw_marks' => 'float',
            'negative_deduction' => 'float',
            'total_marks' => 'float',
            'percentage' => 'float',
            'is_pass' => 'boolean',
            'pending_items' => 'boolean',
            'compiled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CandidateExam, $this>
     */
    public function candidateExam(): BelongsTo
    {
        return $this->belongsTo(CandidateExam::class, 'candidate_exam_id');
    }
}
