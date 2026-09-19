<?php

namespace App\Domain\QuestionBank\Models;

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Models\User;
use Database\Factories\QuestionBank\QuestionVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * One version of a question: all of its content, where it sits in the academic structure, and its
 * workflow status. Editable only while draft or changes-requested — the database refuses anything
 * else (migration 2026_09_19_000103), so a change to an approved question means a new version.
 *
 * @property int $id
 * @property int $question_id
 * @property int $version_no
 * @property int $question_type_id
 * @property int $branch_id
 * @property string|null $vignette
 * @property string $stem
 * @property string|null $lead_in
 * @property string|null $explanation
 * @property array<string, mixed>|null $settings
 * @property float $marks
 * @property float $negative_marks
 * @property int|null $programme_id
 * @property int|null $professional_id
 * @property int|null $term_id
 * @property int $course_id
 * @property int $node_id
 * @property int|null $discipline_id
 * @property int|null $exam_type_id
 * @property int|null $cognitive_level_id
 * @property int|null $difficulty_level_id
 * @property VersionStatus $status
 * @property string $content_hash
 * @property string $search_text
 * @property string $source
 * @property int|null $import_row_id
 * @property int $author_id
 * @property Carbon|null $submitted_at
 * @property int $review_round
 * @property Carbon|null $approved_at
 * @property int|null $approved_by
 * @property Carbon|null $activated_at
 * @property int $created_by
 * @property int|null $updated_by
 */
#[Fillable(['question_id', 'version_no', 'question_type_id', 'branch_id', 'vignette', 'stem', 'lead_in', 'explanation', 'settings', 'marks', 'negative_marks', 'programme_id', 'professional_id', 'term_id', 'course_id', 'node_id', 'discipline_id', 'exam_type_id', 'cognitive_level_id', 'difficulty_level_id', 'status', 'content_hash', 'search_text', 'source', 'import_row_id', 'author_id', 'submitted_at', 'review_round', 'approved_at', 'approved_by', 'activated_at', 'created_by', 'updated_by'])]
final class QuestionVersion extends Model
{
    /** @use HasFactory<QuestionVersionFactory> */
    use HasFactory;

    protected $table = 'qb_question_versions';

    protected static string $factory = QuestionVersionFactory::class;

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'marks' => 'float',
            'negative_marks' => 'float',
            'status' => VersionStatus::class,
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Question, $this>
     */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class, 'question_id');
    }

    /**
     * @return BelongsTo<QuestionType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(QuestionType::class, 'question_type_id');
    }

    /**
     * @return HasMany<QuestionOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(QuestionOption::class, 'version_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<QuestionItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(QuestionItem::class, 'version_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<QuestionAnswer, $this>
     */
    public function answers(): HasMany
    {
        return $this->hasMany(QuestionAnswer::class, 'version_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<RubricCriterion, $this>
     */
    public function rubricCriteria(): HasMany
    {
        return $this->hasMany(RubricCriterion::class, 'version_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<QuestionReference, $this>
     */
    public function references(): HasMany
    {
        return $this->hasMany(QuestionReference::class, 'version_id')->orderBy('sort_order');
    }

    /**
     * @return BelongsToMany<Tag, $this>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'qb_version_tags', 'version_id', 'tag_id');
    }

    /**
     * @return HasMany<VersionMedia, $this>
     */
    public function media(): HasMany
    {
        return $this->hasMany(VersionMedia::class, 'version_id')->orderBy('sort_order');
    }

    /**
     * @return HasMany<VersionStatusLog, $this>
     */
    public function statusLog(): HasMany
    {
        return $this->hasMany(VersionStatusLog::class, 'version_id')->orderBy('id');
    }

    /**
     * @return HasMany<ReviewAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class, 'version_id')->orderBy('id');
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'version_id')->orderBy('id');
    }

    /**
     * Every pre-hoc judgement about this version: the author's proposal, each reviewer's values and
     * the consolidated row.
     *
     * @return HasMany<PrehocAssessment, $this>
     */
    public function prehocAssessments(): HasMany
    {
        return $this->hasMany(PrehocAssessment::class, 'version_id')->orderBy('id');
    }

    /**
     * @return HasOne<PrehocAssessment, $this>
     */
    public function consolidatedPrehoc(): HasOne
    {
        return $this->hasOne(PrehocAssessment::class, 'version_id')->where('is_consolidated', true);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function isEditable(): bool
    {
        return $this->status->isEditable();
    }
}
