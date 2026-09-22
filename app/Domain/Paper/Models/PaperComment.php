<?php

namespace App\Domain\Paper\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A comment the committee leaves while moderating a paper: general, or on one of its items.
 *
 * @property int $id
 * @property int $paper_id
 * @property int|null $item_id
 * @property string $body
 * @property string $status open|resolved
 * @property int $created_by
 * @property int|null $resolved_by
 * @property Carbon|null $resolved_at
 */
#[Fillable(['paper_id', 'item_id', 'body', 'status', 'created_by', 'resolved_by', 'resolved_at'])]
final class PaperComment extends Model
{
    protected $table = 'exm_paper_comments';

    protected function casts(): array
    {
        return ['resolved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Paper, $this>
     */
    public function paper(): BelongsTo
    {
        return $this->belongsTo(Paper::class, 'paper_id');
    }

    /**
     * @return BelongsTo<PaperItem, $this>
     */
    public function item(): BelongsTo
    {
        return $this->belongsTo(PaperItem::class, 'item_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
