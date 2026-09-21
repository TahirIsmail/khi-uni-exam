<?php

namespace App\Domain\Exam\Models;

use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Enums\ExaminationStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One sitting of one Course ID: what it is, when, how long, out of how many marks, and how it is
 * marked. Its blueprint says what the paper must contain; the paper itself comes in the next step.
 *
 * @property int $id
 * @property string $public_ref
 * @property int $branch_id
 * @property string $title
 * @property int $programme_id
 * @property int $professional_id
 * @property int|null $term_id
 * @property int $course_id
 * @property int $exam_type_id
 * @property int|null $intake_id
 * @property Carbon|null $starts_at
 * @property int $duration_minutes
 * @property float $total_marks
 * @property float $pass_percentage
 * @property bool $negative_marking
 * @property float|null $negative_fraction
 * @property string|null $instructions
 * @property ExaminationStatus $status
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable(['public_ref', 'branch_id', 'title', 'programme_id', 'professional_id', 'term_id', 'course_id', 'exam_type_id', 'intake_id', 'starts_at', 'duration_minutes', 'total_marks', 'pass_percentage', 'negative_marking', 'negative_fraction', 'instructions', 'status', 'created_by', 'updated_by'])]
final class Examination extends Model
{
    protected $table = 'exm_examinations';

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'total_marks' => 'float',
            'pass_percentage' => 'float',
            'negative_marking' => 'boolean',
            'negative_fraction' => 'float',
            'status' => ExaminationStatus::class,
        ];
    }

    /**
     * @return HasOne<Blueprint, $this>
     */
    public function blueprint(): HasOne
    {
        return $this->hasOne(Blueprint::class, 'examination_id');
    }

    /**
     * @return HasMany<Section, $this>
     */
    public function sections(): HasMany
    {
        return $this->hasMany(Section::class, 'examination_id')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
