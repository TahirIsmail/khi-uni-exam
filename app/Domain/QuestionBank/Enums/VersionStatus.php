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

    /**
     * KMU's statuses, in the order people see them (KMU requirements, "Question status"). The
     * workflow's finer steps are folded together, and the decision taken on a version tells
     * "Accept" from "Retain in QBank", and "Submitted for Review" from "Review" (reviewed again).
     *
     * @return array<string, string> key => label
     */
    public static function groups(): array
    {
        return [
            'draft' => 'Draft',
            'submitted' => 'Submitted for Review',
            'review' => 'Review',
            'revise' => 'Revise',
            // Accepted is stored in the question bank; the label says so (KMU, 2026-10-06).
            'accept' => 'Accept / QBank',
            'retain' => 'Retain in QBank',
            'removed' => 'Remove / Discard',
        ];
    }

    /** The KMU status of a version, from its workflow step and the decision taken on it. */
    public static function kmuLabel(self $status, ?string $decision): string
    {
        return match ($status) {
            self::Submitted, self::UnderReview => $decision === 'review' ? 'Review' : 'Submitted for Review',
            self::Approved, self::Active => $decision === 'retain' ? 'Retain in QBank' : 'Accept / QBank',
            default => $status->label(),
        };
    }

    /**
     * The status as KMU names it (KMU QBank structure, "Question status"). The workflow keeps its
     * finer steps underneath, but people see one status in the university's own words.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted, self::UnderReview => 'Submitted for Review',
            self::ChangesRequested => 'Revise',
            self::Approved, self::Active => 'Accept / QBank',
            self::OnHold => 'Review',
            self::Superseded => 'Replaced by a newer version',
            self::Retired, self::Archived => 'Remove / Discard',
        };
    }
}
