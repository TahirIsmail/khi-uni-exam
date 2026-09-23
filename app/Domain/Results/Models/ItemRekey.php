<?php

namespace App\Domain\Results\Models;

use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\Results\Enums\RekeyDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A correction to one paper item after the exam — never changed once written (migration
 * 2026_10_02_000102). Corrects the paper's own item, not the reusable question-bank record.
 *
 * @property int $id
 * @property int $paper_item_id
 * @property RekeyDecision $decision
 * @property int|null $corrected_option_id
 * @property string $reason
 * @property int $decided_by
 * @property Carbon $decided_at
 */
#[Fillable(['paper_item_id', 'decision', 'corrected_option_id', 'reason', 'decided_by', 'decided_at'])]
final class ItemRekey extends Model
{
    protected $table = 'exm_item_rekeys';

    protected function casts(): array
    {
        return [
            'decision' => RekeyDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<PaperItem, $this>
     */
    public function paperItem(): BelongsTo
    {
        return $this->belongsTo(PaperItem::class, 'paper_item_id');
    }

    /**
     * @return BelongsTo<QuestionOption, $this>
     */
    public function correctedOption(): BelongsTo
    {
        return $this->belongsTo(QuestionOption::class, 'corrected_option_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
