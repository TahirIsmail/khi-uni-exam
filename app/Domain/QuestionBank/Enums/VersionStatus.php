<?php

namespace App\Domain\QuestionBank\Enums;

/**
 * The life of a question version (blueprint 8.2). The database enforces the same list of steps
 * (migration 2026_09_19_000103), so a wrong move fails even in raw SQL.
 */
enum VersionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case UnderReview = 'under_review';
    case ChangesRequested = 'changes_requested';
    case Approved = 'approved';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Superseded = 'superseded';
    case Retired = 'retired';
    case Archived = 'archived';

    /** Only these two can still be written to; anything else needs a new version. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::ChangesRequested], true);
    }

    public function isUsableInExams(): bool
    {
        return $this === self::Active;
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted, self::Archived],
            self::Submitted => [self::UnderReview, self::ChangesRequested, self::Archived],
            self::UnderReview => [self::ChangesRequested, self::Approved, self::Archived],
            self::ChangesRequested => [self::Submitted, self::Archived],
            self::Approved => [self::Active, self::Archived],
            self::Active => [self::OnHold, self::Superseded, self::Retired, self::Archived],
            self::OnHold => [self::Active, self::Retired, self::Archived],
            self::Superseded, self::Retired, self::Archived => [],
        };
    }

    public function canMoveTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Submitted',
            self::UnderReview => 'Under review',
            self::ChangesRequested => 'Changes requested',
            self::Approved => 'Approved',
            self::Active => 'Active',
            self::OnHold => 'On hold',
            self::Superseded => 'Superseded',
            self::Retired => 'Retired',
            self::Archived => 'Archived',
        };
    }
}
