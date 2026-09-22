<?php

namespace App\Domain\Paper\Enums;

/**
 * The life of a paper. It is a draft while questions are being chosen; moderation, finalising and
 * publishing come after it in the next step of the exam phase.
 */
enum PaperStatus: string
{
    case Draft = 'draft';

    public function isEditable(): bool
    {
        return match ($this) {
            self::Draft => true,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Being built',
        };
    }
}
