<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\UserScope;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Which courses of a campus a user may work in: all of them when kmu-cms sets no exam access
 * limits, otherwise only the courses of the programmes, professionals and courses listed there.
 */
final class CourseScope
{
    public function __construct(private readonly AccessControl $access) {}

    /**
     * @return list<int>|null null means every course in the campus
     */
    public function courseIds(User $user, int $branchId): ?array
    {
        if (! $this->access->canAccessBranch($user, $branchId)) {
            return [];
        }

        $scopes = $this->access->scopes($user);
        if ($scopes === [] || $this->access->isSuperAdmin($user)) {
            return null;
        }

        $ids = static fn (string $type): array => array_values(array_map(
            fn (UserScope $scope): int => $scope->id,
            array_filter($scopes, fn (UserScope $scope): bool => $scope->type === $type),
        )) ?: [0];

        $query = DB::connection('cms')->table('v_cms_courses')->where('branch_id', $branchId)
            ->where(function ($inner) use ($ids): void {
                $inner->whereIn('id', $ids('course'))
                    ->orWhereIn('programme_id', $ids('programme'))
                    ->orWhereIn('professional_id', $ids('professional'));
            });

        return array_values(array_map(fn (mixed $id): int => (int) $id, $query->pluck('id')->all()));
    }

    /**
     * @return list<UserScope>
     */
    public function scopes(User $user): array
    {
        return $this->access->scopes($user);
    }
}
