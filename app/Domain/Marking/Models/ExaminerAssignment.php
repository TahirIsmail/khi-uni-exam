<?php

namespace App\Domain\Marking\Models;

use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Enums\ExaminerRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Who marks or adjudicates one examination's manually-marked items (exam phase, step 20).
 *
 * @property int $id
 * @property int $examination_id
 * @property int $user_id
 * @property ExaminerRole $role
 * @property int $assigned_by
 * @property Carbon $assigned_at
 */
#[Fillable(['examination_id', 'user_id', 'role', 'assigned_by', 'assigned_at'])]
final class ExaminerAssignment extends Model
{
    protected $table = 'mrk_examiner_assignments';

    protected function casts(): array
    {
        return [
            'role' => ExaminerRole::class,
            'assigned_at' => 'datetime',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
