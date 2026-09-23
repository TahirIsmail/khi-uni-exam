<?php

namespace App\Domain\QuestionBank\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One version's statistics from one examination — a recomputed cache, upserted each time analysis
 * is run for that examination (exam phase, step 22), not a fact of its own the way a decision is.
 *
 * @property int $id
 * @property int $version_id
 * @property int $question_id
 * @property int $branch_id
 * @property int|null $exam_id
 * @property string|null $exam_label
 * @property Carbon|null $used_on
 * @property int|null $candidates
 * @property int|null $correct_count
 * @property float|null $observed_p
 * @property float|null $discrimination
 * @property array<string, mixed>|null $option_shares
 */
#[Fillable(['version_id', 'question_id', 'branch_id', 'exam_id', 'exam_label', 'used_on', 'candidates', 'correct_count', 'observed_p', 'discrimination', 'option_shares'])]
final class QuestionUsage extends Model
{
    protected $table = 'qb_question_usage';

    protected function casts(): array
    {
        return [
            'used_on' => 'date',
            'observed_p' => 'float',
            'discrimination' => 'float',
            'option_shares' => 'array',
        ];
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }
}
