<?php

namespace App\Domain\Identity\Authorization;

use Illuminate\Support\Facades\DB;

/**
 * The place an action happens in the academic structure, used for scope checks.
 */
final class ScopeTarget
{
    public function __construct(
        public readonly ?int $programmeId = null,
        public readonly ?int $professionalId = null,
        public readonly ?int $courseId = null,
    ) {}

    public static function programme(int $programmeId): self
    {
        return new self(programmeId: $programmeId);
    }

    /** Resolves the course's professional and programme from kmu-cms; null if the course does not exist. */
    public static function course(int $courseId): ?self
    {
        $course = DB::connection('cms')->table('v_cms_courses')->where('id', $courseId)->first(['programme_id', 'professional_id']);

        return $course === null ? null : new self((int) $course->programme_id, (int) $course->professional_id, $courseId);
    }
}
