<?php

namespace App\Domain\Candidate\Enums;

/**
 * A candidate's way to their seat (exam phase, step 17): enrolled once imported, allocated once a
 * centre and room are chosen for them, checked in once they arrive and are handed their exam PIN.
 * Going backwards is allowed at every step except after check-in, which is frozen at the database
 * (migration 2026_09_29_000103).
 */
enum CandidateStatus: string
{
    case Enrolled = 'enrolled';
    case Allocated = 'allocated';
    case CheckedIn = 'checked_in';

    public function label(): string
    {
        return match ($this) {
            self::Enrolled => 'Not yet allocated',
            self::Allocated => 'Allocated',
            self::CheckedIn => 'Checked in',
        };
    }
}
