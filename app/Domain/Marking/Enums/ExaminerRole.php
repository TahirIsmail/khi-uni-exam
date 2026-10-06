<?php

namespace App\Domain\Marking\Enums;

enum ExaminerRole: string
{
    case First = 'first';
    case Second = 'second';
    case Adjudicator = 'adjudicator';

    public function label(): string
    {
        return match ($this) {
            self::First => 'First examiner',
            self::Second => 'Second examiner',
            self::Adjudicator => 'Adjudicator',
        };
    }
}
