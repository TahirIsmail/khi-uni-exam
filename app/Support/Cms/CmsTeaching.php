<?php

namespace App\Support\Cms;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Who teaches what, read from kmu-cms (Academics → Assign Program Teacher) through
 * v_cms_teaching_assignments.
 *
 * This answers "should this examination be on this teacher's Marking screen", nothing more: being
 * assigned to a programme lets somebody see an examination, while marking it still needs the
 * examiner appointment that App\Domain\Marking\Actions\AssignExaminer records.
 *
 * The assignment names a term as well, but a term there is a `sections` row while an examination's
 * term is an acad_professional_terms row, and the two do not map one to one — so programme and
 * intake are what is matched on, and a teacher of one semester also sees the other semesters of
 * their intake.
 */
final class CmsTeaching
{
    /** @var array<int, list<array{programmeId: int, intakeId: int}>> */
    private array $cache = [];

    /**
     * @return list<array{programmeId: int, intakeId: int}>
     */
    public function assignmentsFor(User $user): array
    {
        $staffId = $user->cms_staff_id;

        if ($staffId === null) {
            return [];
        }

        return $this->cache[$staffId] ??= array_values(
            DB::connection('cms')->table('v_cms_teaching_assignments')
                ->where('staff_id', $staffId)
                ->get(['programme_id', 'intake_id'])
                ->map(fn (stdClass $row): array => [
                    'programmeId' => (int) $row->programme_id,
                    'intakeId' => (int) $row->intake_id,
                ])
                ->all()
        );
    }

    /**
     * An examination with no intake of its own is matched on its programme alone — there is nothing
     * else to match it against.
     */
    public function teaches(User $user, int $programmeId, ?int $intakeId): bool
    {
        foreach ($this->assignmentsFor($user) as $assignment) {
            if ($assignment['programmeId'] !== $programmeId) {
                continue;
            }

            if ($intakeId === null || $assignment['intakeId'] === $intakeId) {
                return true;
            }
        }

        return false;
    }

    public function hasAnyAssignment(User $user): bool
    {
        return $this->assignmentsFor($user) !== [];
    }

    /** Drops what has been read, for when an assignment is made inside the same request or test. */
    public function forget(): void
    {
        $this->cache = [];
    }
}
