<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may review a given question at a given level (blueprint P1.8, KMU QBank mechanism): staff of
 * the same campus who hold that level's review right, whose exam access covers the course, and who
 * did not write it. Whoever has the fewest open reviews comes first, so automatic assignment spreads
 * the work.
 */
final class ReviewerPool
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return list<array{user: User, openLoad: int}>
     */
    public function forVersion(QuestionVersion $version, ReviewStage $stage = ReviewStage::Subject): array
    {
        return $this->forTarget(
            new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id),
            $stage,
            $version->author_id,
        );
    }

    /**
     * Who may review questions of a course at a level, before any question exists — for the author
     * choosing reviewers while writing one. The author is left out.
     *
     * @return list<array{user: User, openLoad: int}>
     */
    public function forTarget(ScopeTarget $target, ReviewStage $stage, ?int $authorId): array
    {
        $load = ReviewAssignment::query()
            ->where('status', 'open')
            ->groupBy('reviewer_id')
            ->pluck(DB::raw('COUNT(*)'), 'reviewer_id') // raw-sql-reviewed: fixed aggregate, no user input
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $pool = [];
        foreach ($this->candidates() as $user) {
            if ($user->id !== $authorId && $this->access->allows($user, $stage->permission(), $target)) {
                $pool[] = ['user' => $user, 'openLoad' => $load[$user->id] ?? 0];
            }
        }

        usort($pool, fn (array $a, array $b): int => [$a['openLoad'], $a['user']->id] <=> [$b['openLoad'], $b['user']->id]);

        return $pool;
    }

    /** Whether this user may review this version at this level. */
    public function allows(User $user, QuestionVersion $version, ReviewStage $stage = ReviewStage::Subject): bool
    {
        return $user->id !== $version->author_id && $this->access->allows(
            $user,
            $stage->permission(),
            new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id),
        );
    }

    /** Whether this user may review this version at either level — to open the workspace. */
    public function allowsAny(User $user, QuestionVersion $version): bool
    {
        return $this->allows($user, $version, ReviewStage::Subject) || $this->allows($user, $version, ReviewStage::Academic);
    }

    /**
     * @return list<User>
     */
    private function candidates(): array
    {
        return array_values(User::query()
            ->where('is_active', true)
            ->whereNotNull('cms_staff_id')
            ->orderBy('id')
            ->get()
            ->all());
    }
}
