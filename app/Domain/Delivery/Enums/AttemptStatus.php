<?php

namespace App\Domain\Delivery\Enums;

/**
 * An attempt's way through the exam (ADR-0003). The database enforces the same steps (migration
 * 2026_09_30_000102), so a wrong move fails even in raw SQL.
 */
enum AttemptStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Paused = 'paused';
    case Submitted = 'submitted';
    case Voided = 'voided';

    public function label(): string
    {
        return match ($this) {
            self::NotStarted => 'Not started',
            self::InProgress => 'In progress',
            self::Paused => 'Paused',
            self::Submitted => 'Submitted',
            self::Voided => 'Voided',
        };
    }
}
