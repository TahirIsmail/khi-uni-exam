<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\ReviewAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Who may review a given question (blueprint P1.8): staff of the same campus who are allowed to
 * review, whose exam access covers the course, and who did not write it. Whoever has the fewest
 * open reviews comes first, so automatic assignment spreads the work.
 */
final class ReviewerPool
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return list<array{user: User, openLoad: int}>
     */
    public function forVersion(QuestionVersion $version): array
    {
        $target = new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id);

        $load = ReviewAssignment::query()
            ->where('status', 'open')
            ->groupBy('reviewer_id')
            ->pluck(DB::raw('COUNT(*)'), 'reviewer_id') // raw-sql-reviewed: fixed aggregate, no user input
            ->map(fn (mixed $count): int => (int) $count)
            ->all();

        $pool = [];
        foreach ($this->candidates() as $user) {
            if ($user->id === $version->author_id) {
                continue;
            }
            if (! $this->access->allows($user, 'qbank.review.perform', $target)) {
                continue;
            }

            $pool[] = ['user' => $user, 'openLoad' => $load[$user->id] ?? 0];
        }

        usort($pool, fn (array $a, array $b): int => [$a['openLoad'], $a['user']->id] <=> [$b['openLoad'], $b['user']->id]);

        return $pool;
    }

    /** Whether this user may review this version at all — used before opening the workspace. */
    public function allows(User $user, QuestionVersion $version): bool
    {
        return $user->id !== $version->author_id && $this->access->allows(
            $user,
            'qbank.review.perform',
            new ScopeTarget($version->branch_id, $version->programme_id, $version->professional_id, $version->course_id),
        );
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
