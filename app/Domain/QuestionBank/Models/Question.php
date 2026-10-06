<?php

namespace App\Domain\QuestionBank\Models;

use App\Models\User;
use Database\Factories\QuestionBank\QuestionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A question: a stable identity in one campus and course, with a chain of versions. The content is
 * on the versions; at most one of them is active. Questions are archived, never deleted.
 *
 * @property int $id
 * @property string $public_ref
 * @property int $branch_id
 * @property int $course_id
 * @property int|null $active_version_id
 * @property int $latest_version_no
 * @property int $times_used
 * @property int $candidates_total
 * @property Carbon|null $last_used_at
 * @property float|null $last_p
 * @property float|null $last_d
 * @property bool $is_archived
 * @property Carbon|null $archived_at
 * @property int|null $archived_by
 * @property string|null $archive_reason
 * @property int $created_by
 */
#[Fillable(['branch_id', 'course_id', 'public_ref', 'active_version_id', 'latest_version_no', 'times_used', 'candidates_total', 'last_used_at', 'last_p', 'last_d', 'is_archived', 'archived_at', 'archived_by', 'archive_reason', 'created_by'])]
final class Question extends Model
{
    /** @use HasFactory<QuestionFactory> */
    use HasFactory;

    protected $table = 'qb_questions';

    protected static string $factory = QuestionFactory::class;

    protected function casts(): array
    {
        return [
            'is_archived' => 'boolean',
            'archived_at' => 'datetime',
            'last_used_at' => 'datetime',
            'last_p' => 'float',
            'last_d' => 'float',
        ];
    }

    /**
     * @return HasMany<QuestionVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(QuestionVersion::class, 'question_id');
    }

    /**
     * @return BelongsTo<QuestionVersion, $this>
     */
    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(QuestionVersion::class, 'active_version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
