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
use Illuminate\Database\Eloquent\Builder;
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
        private readonly ReviewLevels $levels,
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
            'stageLabel' => $this->levels->label(ReviewStage::from($assignment->stage)),
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

        // How many reviews this round has, shown beside each question as advice.
        // With one level of review, every review of the round counts, whatever level it was asked at.
        $single = $this->levels->single();
        $reviewsIn = fn (ReviewStage $stage) => DB::table('qb_reviews')
            ->selectRaw('COUNT(*)') // raw-sql-reviewed: fixed aggregate, no user input
            ->whereColumn('qb_reviews.version_id', 'qb_question_versions.id')
            ->where('qb_reviews.outcome', 'reviewed')
            ->when(! $single, fn ($query) => $query->where('qb_reviews.stage', $stage->value))
            ->whereColumn('qb_reviews.round', 'qb_question_versions.review_round');

        return $this->approvalQuery($approver, $branchId, $show, $courseIds)
            ->select('qb_question_versions.*')
            ->selectSub($reviewsIn(ReviewStage::Subject), 'subject_in')
            ->selectSub($reviewsIn(ReviewStage::Academic), 'academic_in')
            ->with(['type:id,name', 'question:id,public_ref', 'reviews'])
            ->paginate(20)
            ->withQueryString()
            ->through(fn (QuestionVersion $version): array => $this->presentForApproval(
                $version,
                $this->approval->reviewsOfRound($version, $version->reviews),
                $version->author_id === $approver->id,
            ));
    }

    /**
     * The questions before and after this one in the approver's "Ready to decide" list, so they go
     * from one question to the next without going back to the list. A question that is not in the
     * list (decided already, or their own) is followed by the first one still waiting.
     *
     * @return array{previous: array{questionId: int, versionId: int, reference: string}|null, next: array{questionId: int, versionId: int, reference: string}|null, position: int|null, total: int}
     */
    public function approvalNeighbours(User $approver, int $branchId, QuestionVersion $version): array
    {
        $queue = $this->approvalQuery($approver, $branchId, 'ready', $this->accessibleCourseIds($approver, $branchId))
            ->with('question:id,public_ref')
            ->get(['id', 'question_id', 'version_no'])
            ->map(fn (QuestionVersion $row): array => [
                'questionId' => $row->question_id,
                'versionId' => $row->id,
                'reference' => $row->question->public_ref.' v'.$row->version_no,
            ])
            ->values()
            ->all();

        $at = array_search($version->id, array_column($queue, 'versionId'), true);

        return [
            'previous' => $at === false || $at === 0 ? null : $queue[$at - 1],
            'next' => $at === false ? ($queue[0] ?? null) : ($queue[$at + 1] ?? null),
            'position' => $at === false ? null : $at + 1,
            'total' => count($queue),
        ];
    }

    /**
     * Where the approver goes once they have decided about a question: the one after it in their
     * list, else the first still waiting; null when nothing is left.
     *
     * @return array{questionId: int, versionId: int, reference: string}|null
     */
    public function nextToDecide(User $approver, int $branchId, QuestionVersion $decided): ?array
    {
        $queue = fn (): Builder => $this->approvalQuery($approver, $branchId, 'ready', $this->accessibleCourseIds($approver, $branchId))
            ->where('qb_question_versions.id', '<>', $decided->id)
            ->with('question:id,public_ref');

        $after = $decided->submitted_at === null ? null : $queue()
            ->where(fn ($query) => $query->where('submitted_at', '>', $decided->submitted_at)
                ->orWhere(fn ($query) => $query->where('submitted_at', $decided->submitted_at)->where('qb_question_versions.id', '>', $decided->id)))
            ->first();
        $next = $after ?? $queue()->first();

        return $next instanceof QuestionVersion ? [
            'questionId' => $next->question_id,
            'versionId' => $next->id,
            'reference' => $next->question->public_ref.' v'.$next->version_no,
        ] : null;
    }

    /**
     * The approver's list, in the order it is shown.
     *
     * @param  list<int>|null  $courseIds
     * @return Builder<QuestionVersion>
     */
    private function approvalQuery(User $approver, int $branchId, string $show, ?array $courseIds): Builder
    {
        return QuestionVersion::query()
            ->where('branch_id', $branchId)
            ->whereIn('status', $show === 'approved' ? [VersionStatus::Approved] : [VersionStatus::Submitted, VersionStatus::UnderReview])
            ->when($courseIds !== null, fn ($query) => $query->whereIn('course_id', $courseIds ?? []))
            // The approving authority decides without waiting for the reviewers, so everything in
            // review is ready for them — except their own questions, which somebody else decides.
            ->when($show === 'ready', fn ($query) => $query->where('author_id', '<>', $approver->id))
            ->when($show === 'waiting', fn ($query) => $query->where('author_id', '=', $approver->id))
            ->orderBy('submitted_at')
            ->orderBy('id');
    }

    /**
     * @param  list<Review>  $reviews
     * @return array<string, mixed>
     */
    private function presentForApproval(QuestionVersion $version, array $reviews, bool $isMine): array
    {
        $problem = $this->approval->gate($version, $reviews, reviewsNeeded: false);

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
            'subjectIn' => count(array_filter($reviews, fn (Review $review): bool => ! $review->requestedChanges() && $this->levels->counts($review, ReviewStage::Subject))),
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
                'stageLabel' => $this->levels->label(ReviewStage::from($review->stage)),
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
                'stageLabel' => $this->levels->label(ReviewStage::from($assignment->stage)),
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
            'myStageLabel' => $mine === null ? null : $this->levels->label(ReviewStage::from($mine->stage)),
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
            'academicNeeded' => $this->settings->academicReview() ? 1 : 0,
            'singleLevel' => $this->levels->single(),
            'autoActivate' => $this->settings->autoActivate(),
            'reviewerAcceptStores' => $this->settings->reviewerAcceptStores(),
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
            fn (Review $review): bool => ! $review->requestedChanges() && $this->levels->counts($review, $stage),
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
