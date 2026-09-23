<?php

namespace App\Domain\Marking\Enums;

/**
 * Where one mark for one item came from. "The final mark" for an item is decided by priority, not
 * stored separately: adjudicator if present, else final (the examiners' agreed average), else
 * examiner_1 (single-marking) or auto — see App\Domain\Marking\Queries\FinalMark.
 */
enum MarkSource: string
{
    case Auto = 'auto';
    case Examiner1 = 'examiner_1';
    case Examiner2 = 'examiner_2';
    case Adjudicator = 'adjudicator';
    case Final = 'final';

    public function label(): string
    {
        return match ($this) {
            self::Auto => 'Marked automatically',
            self::Examiner1 => 'First examiner',
            self::Examiner2 => 'Second examiner',
            self::Adjudicator => 'Adjudicator',
            self::Final => 'Agreed average',
        };
    }
}
