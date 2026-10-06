<?php

namespace App\Domain\Delivery\Enums;

/**
 * The committee's call against a proctoring case (exam-phase.md, step 19). Voiding the attempt
 * transitions it the way an invigilator's other actions do — recorded, never silently applied.
 */
enum ProctorDecisionType: string
{
    case NoAction = 'no_action';
    case Warning = 'warning';
    case FlaggedForReview = 'flagged_for_review';
    case VoidAttempt = 'void_attempt';

    public function label(): string
    {
        return match ($this) {
            self::NoAction => 'No action',
            self::Warning => 'Warning',
            self::FlaggedForReview => 'Flagged for review',
            self::VoidAttempt => 'Attempt voided',
        };
    }
}
