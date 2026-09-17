<?php

namespace App\Domain\Identity\Authorization;

use Illuminate\Support\Facades\DB;

/**
 * The place an action happens: always a branch (campus), and optionally a programme, professional
 * and course inside it. Build it with the resolvers so the parts come from kmu-cms and agree.
 */
final readonly class ScopeTarget
{
    public function __construct(
        public int $branchId,
        public ?int $programmeId = null,
        public ?int $professionalId = null,
        public ?int $courseId = null,
    ) {}

    public static function branch(int $branchId): self
    {
        return new self($branchId);
    }

    /** Null if the programme does not exist or has no branch. */
    public static function programme(int $programmeId): ?self
    {
        $programme = DB::connection('cms')->table('v_cms_programmes')->where('id', $programmeId)->first(['branch_id']);

        return $programme?->branch_id === null ? null : new self((int) $programme->branch_id, $programmeId);
    }

    /** Null if the professional does not exist or its programme has no branch. */
    public static function professional(int $professionalId): ?self
    {
        $professional = DB::connection('cms')->table('v_cms_professionals')->where('id', $professionalId)->first(['branch_id', 'programme_id']);

        return $professional?->branch_id === null ? null : new self((int) $professional->branch_id, (int) $professional->programme_id, $professionalId);
    }

    /** Null if the course does not exist or its programme has no branch. */
    public static function course(int $courseId): ?self
    {
        $course = DB::connection('cms')->table('v_cms_courses')->where('id', $courseId)->first(['branch_id', 'programme_id', 'professional_id']);

        return $course?->branch_id === null
            ? null
            : new self((int) $course->branch_id, (int) $course->programme_id, $course->professional_id === null ? null : (int) $course->professional_id, $courseId);
    }
}
