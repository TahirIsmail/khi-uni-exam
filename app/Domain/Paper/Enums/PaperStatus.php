<?php

namespace App\Domain\Paper\Enums;

/**
 * The life of a paper (exam phase, step 4). The database enforces the same steps (migration
 * 2026_09_28_000102), so a wrong move fails even in raw SQL.
 */
enum PaperStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Finalised = 'finalised';
    case Published = 'published';

    /** Only a draft can be changed — its items, or its own settings. */
    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft => true,
            default => false,
        };
    }

    /** Comments can be added and resolved while the paper is being moderated. */
    public function isModerating(): bool
    {
        return match ($this) {
            self::Submitted, self::Approved => true,
            default => false,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Being built',
            self::Submitted => 'Awaiting moderation',
            self::Approved => 'Approved — ready to finalise',
            self::Finalised => 'Finalised',
            self::Published => 'Published',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Draft => [self::Submitted],
            self::Submitted => [self::Draft, self::Approved],
            self::Approved => [self::Draft, self::Finalised],
            self::Finalised => [self::Published],
            self::Published => [],
        };
    }
}
