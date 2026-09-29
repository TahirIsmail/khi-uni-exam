<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocAssessment;
use App\Domain\QuestionBank\Models\PrehocDecision;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Review;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use App\Support\Cms\CmsSettings;
use App\Support\Html\QuestionHtml;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * What the review screens show: a reviewer's own queue, the approver's queue, and everything one
 * version's reviews say. Reviewer names are hidden from authors when kmu-cms asks for that; the
 * comments and decisions are always shown.
 */
final class ReviewBoard
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly ChecklistRules $checklist,
        private readonly ApproveVersion $approval,
        private readonly CmsAcademic $academic,
        private readonly CmsSettings $settings,
    ) {}

    /**
     * The questions waiting for this reviewer, soonest due first.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function myQueue(User $reviewer, int $branchId, string $show = 'open'): LengthAwarePaginator
    {
        return ReviewAssignment::query()
            ->where('reviewer_id', $reviewer->id)
            ->where('branch_id', $branchId)
            ->when($show === 'open', fn ($query) => $query->where('status', 'open'))
            ->when($show === 'done', fn ($query) => $query->where('status', 'submitted'))
            ->with(['version.type:id,name', 'version.question:id,public_ref', 'review'])
            ->orderByRaw('CASE WHEN status = \'open\' THEN 0 ELSE 1 END') // raw-sql-reviewed: fixed expression, no user input
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->through(fn (ReviewAssignment $assignment): array => $this->presentAssignment($assignment));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAssignment(ReviewAssignment $assignment): array
    {
        return [
            'id' => $assignment->id,
            'stage' => $assignment->stage,
            'stageLabel' => ReviewStage::from($assignment->stage)->label(),
            'status' => $assignment->status,
            'isOverdue' => $assignment->isOverdue(),
            'dueAt' => $assignment->due_at?->toIso8601String(),
            'assignedAt' => $assignment->assigned_at->toIso8601String(),
            'submittedAt' => $assignment->submitted_at?->toIso8601String(),
            'outcome' => $assignment->review?->outcome,
            'questionId' => $assignment->question_id,
            'versionId' => $assignment->version_id,
            'reference' => $assignment->version->question->public_ref,
            'versionNo' => $assignment->version->version_no,
            'versionStatus' => $assignment->version->kmuStatus(),
            'type' => $assignment->version->type->name,
            'course' => $this->academic->courseLabel($assignment->version->course_id) ?? ('#'.$assignment->version->course_id),
            'marks' => $assignment->version->marks,
            'summary' => mb_substr(QuestionHtml::toText($assignment->version->stem), 0, 160),
        ];
    }

    /**
     * The questions an approver has to decide about: those under review in this campus, with how
     * many reviews are in and whether anything blocks approval.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function approvalQueue(User $approver, int $branchId, string $show = 'ready'): LengthAwarePaginator
    {
        $courseIds = $this->accessibleCourseIds($approver, $branchId);
        $required = $this->settings->reviewsRequired();

        // How many reviews this round has is counted in SQL, so that "ready" and "still in review"
        // are real pages: filtering after paging would leave holes and empty pages.
        $reviewsIn = fn (ReviewStage $stage) => DB::table('qb_reviews')
            ->selectRaw('COUNT(*)') // raw-sql-reviewed: fixed aggregate, no user input
            ->whereColumn('qb_reviews.version_id', 'qb_question_versions.id')
            ->where('qb_reviews.outcome', 'reviewed')
            ->where('qb_reviews.stage', $stage->value)
            ->whereColumn('qb_reviews.round', 'qb_question_versions.review_round');

        return QuestionVersion::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $show === 'approved' ? [VersionStatus::Approved] : [VersionStatus::Submitted, VersionStatus::UnderReview])
            ->when($courseIds !== null, fn ($query) => $query->whereIn('course_id', $courseIds ?? []))
            ->select('qb_question_versions.*')
            ->selectSub($reviewsIn(ReviewStage::Subject), 'subject_in')
            ->selectSub($reviewsIn(ReviewStage::Academic), 'academic_in')
            // Grouping by the primary key leaves the rows alone, but it lets HAVING name author_id:
            // under ONLY_FULL_GROUP_BY a plain column in HAVING is rejected (MySQL error 1463)
            // unless it is functionally dependent on the grouped key.
            ->when(in_array($show, ['ready', 'waiting'], true), fn ($query) => $query->groupBy('qb_question_versions.id'))
            ->when($show === 'ready', fn ($query) => $query->havingRaw('subject_in >= ? AND academic_in >= 1 AND author_id <> ?', [$required, $approver->id])) // raw-sql-reviewed: bound values
            ->when($show === 'waiting', fn ($query) => $query->havingRaw('(subject_in < ? OR academic_in < 1 OR author_id = ?)', [$required, $approver->id])) // raw-sql-reviewed: bound values
            ->with(['type:id,name', 'question:id,public_ref', 'reviews'])
            ->orderBy('submitted_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (QuestionVersion $version): array => $this->presentForApproval(
                $version,
                $this->approval->reviewsOfRound($version, $version->reviews),
                $version->author_id === $approver->id,
            ));
    }

    /**
     * @param  list<Review>  $reviews
     * @return array<string, mixed>
     */
    private function presentForApproval(QuestionVersion $version, array $reviews, bool $isMine): array
    {
        $problem = $this->approval->gate($version, $reviews);

        return [
            'questionId' => $version->question_id,
            'versionId' => $version->id,
            'reference' => $version->question->public_ref,
            'versionNo' => $version->version_no,
            'status' => $version->status->value,
            'statusLabel' => $version->kmuStatus(),
            'type' => $version->type->name,
            'course' => $this->academic->courseLabel($version->course_id) ?? ('#'.$version->course_id),
            'marks' => $version->marks,
            'summary' => mb_substr(QuestionHtml::toText($version->stem), 0, 160),
            'submittedAt' => $version->submitted_at?->toIso8601String(),
            'subjectIn' => count(array_filter($reviews, fn (Review $review): bool => ! $review->requestedChanges() && $review->stage === ReviewStage::Subject->value)),
            'subjectNeeded' => $this->settings->reviewsRequired(),
            'academicIn' => count(array_filter($reviews, fn (Review $review): bool => ! $review->requestedChanges() && $review->stage === ReviewStage::Academic->value)),
            'isMine' => $isMine,
            'blockedBecause' => $isMine ? 'You wrote this question, so somebody else has to approve it.' : ($problem === null ? null : implode(' ', $problem)),
        ];
    }

    /**
     * Everything one version's review needs: who is reviewing it, what each review said, the
     * pre-hoc values recorded so far, the checklist that applies and the decisions to choose from.
     *
     * @return array<string, mixed>
     */
    public function forVersion(User $viewer, QuestionVersion $version, bool $namesVisible): array
    {
        $reviews = Review::query()
            ->where('version_id', $version->id)
            ->with(['decision:id,code,name', 'prehoc'])
            ->orderBy('id')
            ->get();

        $assignments = ReviewAssignment::query()
            ->where('version_id', $version->id)
            ->orderBy('id')
            ->get();

        $names = DB::table('users')
            ->whereIn('id', $reviews->pluck('reviewer_id')->merge($assignments->pluck('reviewer_id'))->unique()->all() ?: [0])
            ->pluck('name', 'id');

        $levels = $this->levelNames();
        $mine = $assignments->firstWhere(fn (ReviewAssignment $assignment): bool => $assignment->reviewer_id === $viewer->id && $assignment->isOpen());

        return [
            'reviews' => $reviews->map(fn (Review $review): array => [
                'id' => $review->id,
                'stage' => $review->stage,
                'stageLabel' => ReviewStage::from($review->stage)->label(),
                'reviewer' => $namesVisible ? ($names[$review->reviewer_id] ?? 'Unknown') : 'A reviewer',
                'isMe' => $review->reviewer_id === $viewer->id,
                'outcome' => $review->outcome,
                'decision' => $review->decision?->name,
                'comments' => $review->comments,
                'checklist' => $this->describeChecklist($review->checklist ?? []),
                'failedRequired' => $this->checklist->failedRequired($review->checklist ?? []),
                'submittedAt' => $review->submitted_at->toIso8601String(),
                'prehoc' => $review->prehoc === null ? null : $this->describePrehoc($review->prehoc, $levels),
            ])->values()->all(),
            'assignments' => $assignments->map(fn (ReviewAssignment $assignment): array => [
                'id' => $assignment->id,
                'stage' => $assignment->stage,
                'stageLabel' => ReviewStage::from($assignment->stage)->label(),
                'reviewer' => $namesVisible ? ($names[$assignment->reviewer_id] ?? 'Unknown') : 'A reviewer',
                'isMe' => $assignment->reviewer_id === $viewer->id,
                'status' => $assignment->status,
                'isOverdue' => $assignment->isOverdue(),
                'dueAt' => $assignment->due_at?->toIso8601String(),
                'assignedAt' => $assignment->assigned_at->toIso8601String(),
                'wasAutomatic' => $assignment->assigned_by === null,
                'cancelReason' => $assignment->cancel_reason,
            ])->values()->all(),
            'prehoc' => PrehocAssessment::query()
                ->where('version_id', $version->id)
                ->orderBy('id')
                ->get()
                ->map(fn (PrehocAssessment $row): array => $this->describePrehoc($row, $levels))
                ->values()->all(),
            'myAssignmentId' => $mine?->id,
            'myStage' => $mine?->stage,
            'myStageLabel' => $mine === null ? null : ReviewStage::from($mine->stage)->label(),
            'checklistItems' => array_map(fn ($item): array => [
                'code' => $item->code,
                'text' => $item->text,
                'guidance' => $item->guidance,
                'isRequired' => $item->is_required,
            ], $this->checklist->items($version->type->family)),
            'decisions' => PrehocDecision::query()->where('is_active', true)->orderBy('sort_order')->get()
                ->map(fn (PrehocDecision $decision): array => [
                    'id' => $decision->id,
                    'code' => $decision->code,
                    'name' => $decision->name,
                    'description' => $decision->description,
                    'isAccept' => $decision->is_accept,
                    'needsComment' => $decision->needs_comment,
                ])->values()->all(),
            'reviewsNeeded' => $this->settings->reviewsRequired(),
            'subjectIn' => $this->countReviewed($version, $reviews, ReviewStage::Subject),
            'academicIn' => $this->countReviewed($version, $reviews, ReviewStage::Academic),
            'autoActivate' => $this->settings->autoActivate(),
        ];
    }

    /**
     * Reviews of this round at one level that did not ask for changes.
     *
     * @param  iterable<int, Review>  $reviews
     */
    private function countReviewed(QuestionVersion $version, iterable $reviews, ReviewStage $stage): int
    {
        return count(array_filter(
            $this->approval->reviewsOfRound($version, $reviews),
            fn (Review $review): bool => ! $review->requestedChanges() && $review->stage === $stage->value,
        ));
    }

    /** Whether the author of this question may see who reviewed it. */
    public function namesVisibleTo(User $viewer, QuestionVersion $version): bool
    {
        if (! $this->settings->reviewerAnonymous()) {
            return true;
        }

        // Anonymity protects reviewers from the author; everyone else in the process sees the names.
        return $version->author_id !== $viewer->id;
    }

    /**
     * The courses the user may work in, or null when they may work in all of their campus.
     *
     * @return list<int>|null
     */
    private function accessibleCourseIds(User $user, int $branchId): ?array
    {
        if ($this->access->scopes($user) === [] || $this->access->isSuperAdmin($user)) {
            return null;
        }

        return array_values(array_map(
            fn (array $course): int => $course['id'],
            array_filter($this->academic->courses($branchId), fn (array $course): bool => $this->access->allows(
                $user,
                'qbank.question.approve',
                new ScopeTarget($branchId, $course['programme_id'], $course['professional_id'], $course['id']),
            )),
        ));
    }

    /**
     * @param  array<int, mixed>  $checklist
     * @return list<array{text: string, pass: bool, note: string|null, isRequired: bool}>
     */
    private function describeChecklist(array $checklist): array
    {
        $items = [];
        foreach ($this->checklist->items() as $item) {
            $items[$item->code] = $item;
        }

        $described = [];
        foreach ($checklist as $row) {
            if (! is_array($row) || ! isset($row['code'])) {
                continue;
            }
            $item = $items[(string) $row['code']] ?? null;
            if ($item === null) {
                continue;
            }

            $note = isset($row['note']) && is_string($row['note']) ? $row['note'] : null;
            $described[] = ['text' => $item->text, 'pass' => (bool) ($row['pass'] ?? false), 'note' => $note, 'isRequired' => $item->is_required];
        }

        return $described;
    }

    /**
     * @param  array{cognitive: array<int, string>, difficulty: array<int, string>}  $levels
     * @return array<string, mixed>
     */
    private function describePrehoc(PrehocAssessment $row, array $levels): array
    {
        return [
            'id' => $row->id,
            'source' => $row->source,
            'reviewId' => $row->review_id,
            'cognitive' => $row->cognitive_level_id === null ? null : ($levels['cognitive'][$row->cognitive_level_id] ?? null),
            'difficulty' => $row->difficulty_level_id === null ? null : ($levels['difficulty'][$row->difficulty_level_id] ?? null),
            'cognitiveLevelId' => $row->cognitive_level_id,
            'difficultyLevelId' => $row->difficulty_level_id,
            'estimatedP' => $row->estimated_p,
            'reason' => $row->reason,
            'isConsolidated' => $row->is_consolidated,
            'assessedAt' => $row->assessed_at->toIso8601String(),
        ];
    }

    /**
     * @return array{cognitive: array<int, string>, difficulty: array<int, string>}
     */
    private function levelNames(): array
    {
        return [
            'cognitive' => DB::table('qb_cognitive_levels')->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all(),
            'difficulty' => DB::table('qb_difficulty_levels')->pluck('name', 'id')->map(fn (mixed $name): string => (string) $name)->all(),
        ];
    }
}
