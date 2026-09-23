<?php

namespace App\Domain\Results\Models;

use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Enums\PublicationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The approve/publish workflow for one examination's results, all attempts at once — the same
 * granularity the blueprint and paper are already approved at.
 *
 * @property int $id
 * @property int $examination_id
 * @property PublicationStatus $status
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $published_by
 * @property Carbon|null $published_at
 */
#[Fillable(['examination_id', 'status', 'approved_by', 'approved_at', 'published_by', 'published_at'])]
final class ResultPublication extends Model
{
    protected $table = 'exm_result_publications';

    protected function casts(): array
    {
        return [
            'status' => PublicationStatus::class,
            'approved_at' => 'datetime',
            'published_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function publishedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
