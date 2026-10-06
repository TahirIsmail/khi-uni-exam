<?php

namespace App\Domain\QuestionBank\Review;

/**
 * The two levels of review before approval (KMU QBank mechanism): the Department / Subject
 * Reviewer checks the question first, then a QBank / Academic Reviewer (DME/DDE) checks its
 * quality and suitability. Each level has its own permission in kmu-cms.
 */
enum ReviewStage: string
{
    case Subject = 'subject';
    case Academic = 'academic';

    public function permission(): string
    {
        return match ($this) {
            self::Subject => 'qbank.review.perform',
            self::Academic => 'qbank.review.academic',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Subject => 'Department / Subject review',
            self::Academic => 'QBank / Academic review',
        };
    }
}
