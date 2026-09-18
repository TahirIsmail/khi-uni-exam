<?php

namespace App\Domain\QuestionBank\Review;

/**
 * What a reviewer submits: either "request changes" with a comment, or a review with a decision,
 * the checklist and (if they may record it) the pre-hoc judgement.
 */
final readonly class ReviewInput
{
    /**
     * @param  array<int, mixed>  $checklist  [{code, pass, note}]
     */
    public function __construct(
        public string $outcome,
        public ?int $decisionId = null,
        public ?string $comments = null,
        public array $checklist = [],
        public ?int $cognitiveLevelId = null,
        public ?int $difficultyLevelId = null,
        public ?float $estimatedP = null,
    ) {}

    public function requestsChanges(): bool
    {
        return $this->outcome === 'changes_requested';
    }

    public function hasPrehocValues(): bool
    {
        return $this->cognitiveLevelId !== null || $this->difficultyLevelId !== null || $this->estimatedP !== null;
    }
}
