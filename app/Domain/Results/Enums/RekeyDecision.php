<?php

namespace App\Domain\Results\Enums;

enum RekeyDecision: string
{
    case Discard = 'discard';
    case CorrectOption = 'correct_option';

    public function label(): string
    {
        return match ($this) {
            self::Discard => 'Discarded (full marks to everyone)',
            self::CorrectOption => 'Correct option changed',
        };
    }
}
