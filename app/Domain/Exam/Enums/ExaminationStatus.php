<?php

namespace App\Domain\Exam\Enums;

/**
 * Where an examination is in its life. The blueprint has its own status (BlueprintStatus); this is
 * the examination's, and later steps of the exam phase add the paper, the sitting and the results
 * to it.
 */
enum ExaminationStatus: string
{
    /** Its details and blueprint are being prepared. */
    case Draft = 'draft';

    /** The blueprint is approved: the paper can be built. */
    case BlueprintApproved = 'blueprint_approved';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Being prepared',
            self::BlueprintApproved => 'Blueprint approved',
        };
    }
}
