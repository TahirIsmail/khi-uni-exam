<?php

namespace App\Domain\Reports\Queries;

/**
 * The group of students a result sheet is about: one professional year of one programme, in one
 * intake, on one campus — and for a semester programme, one term of it.
 *
 * There is no student record spanning examinations, so within a cohort a candidate number is taken
 * to mean one student. See App\Domain\Reports\Queries\TabulationSheet for what happens when two
 * examinations disagree about who that is.
 */
final readonly class CohortKey
{
    public function __construct(
        public int $branchId,
        public int $programmeId,
        public int $professionalId,
        public ?int $intakeId,
        public ?int $termId = null,
    ) {}

    public function withTerm(?int $termId): self
    {
        return new self($this->branchId, $this->programmeId, $this->professionalId, $this->intakeId, $termId);
    }
}
