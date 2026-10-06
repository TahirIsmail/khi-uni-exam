<?php

namespace App\Domain\QuestionBank\Review;

/**
 * What an approver settles on after reading the reviews: the decision, the values that go onto the
 * question, and — when the reviewers disagreed — why this is the answer.
 */
final readonly class ConsolidatedPrehoc
{
    public function __construct(
        public int $decisionId,
        public ?int $cognitiveLevelId = null,
        public ?int $difficultyLevelId = null,
        public ?float $estimatedP = null,
        public ?string $reason = null,
    ) {}
}
