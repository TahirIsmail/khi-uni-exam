<?php

namespace App\Domain\Candidate\Models;

use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Exam\Models\Examination;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One candidate sitting one examination: who they are, where they are allocated to sit, and their
 * way through check-in. See migration 2026_09_29_000102 for why allocation, extra time and the PIN
 * all live on this one row.
 *
 * @property int $id
 * @property int $examination_id
 * @property int $branch_id
 * @property string $candidate_no
 * @property string $name
 * @property string|null $roll_no
 * @property string|null $cnic
 * @property string|null $email
 * @property string|null $phone
 * @property CandidateStatus $status
 * @property int|null $centre_id
 * @property int|null $room_id
 * @property string|null $seat_no
 * @property int|null $allocated_by
 * @property Carbon|null $allocated_at
 * @property int|null $extra_time_minutes
 * @property string|null $extra_time_reason
 * @property int|null $extra_time_granted_by
 * @property Carbon|null $extra_time_granted_at
 * @property string|null $pin_hash
 * @property int|null $pin_issued_by
 * @property Carbon|null $pin_issued_at
 * @property int|null $checked_in_by
 * @property Carbon|null $checked_in_at
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable([
    'examination_id', 'branch_id', 'candidate_no', 'name', 'roll_no', 'cnic', 'email', 'phone', 'status',
    'centre_id', 'room_id', 'seat_no', 'allocated_by', 'allocated_at',
    'extra_time_minutes', 'extra_time_reason', 'extra_time_granted_by', 'extra_time_granted_at',
    'pin_hash', 'pin_issued_by', 'pin_issued_at', 'checked_in_by', 'checked_in_at',
    'created_by', 'updated_by',
])]
final class Candidate extends Model
{
    protected $table = 'cand_candidates';

    protected function casts(): array
    {
        return [
            'status' => CandidateStatus::class,
            'allocated_at' => 'datetime',
            'extra_time_granted_at' => 'datetime',
            'pin_issued_at' => 'datetime',
            'checked_in_at' => 'datetime',
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
     * @return BelongsTo<Centre, $this>
     */
    public function centre(): BelongsTo
    {
        return $this->belongsTo(Centre::class, 'centre_id');
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }
}
