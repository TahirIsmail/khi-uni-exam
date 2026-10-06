<?php

namespace App\Domain\Blueprint\Enums;

/**
 * The life of a blueprint: written as a draft, submitted for approval, then approved. An approver can
 * send a submitted blueprint back, and can reopen an approved one; both return it to a draft. The
 * database enforces the same steps (migration 2026_09_26_000102), so nothing can skip one.
 */
enum BlueprintStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';

    public function isEditable(): bool
    {
        return $this === self::Draft;
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Blueprint in preparation',
            self::Submitted => 'Awaiting approval',
            self::Approved => 'Ready for the paper',
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
            self::Approved => [self::Draft],
        };
    }
}
