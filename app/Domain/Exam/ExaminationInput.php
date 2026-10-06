<?php

namespace App\Domain\Exam;

use Carbon\CarbonImmutable;

/**
 * What the examination form sends, once its shape has been checked. programme, year and term are
 * not here on purpose: they are read from the chosen course, so they can never disagree with it.
 */
final readonly class ExaminationInput
{
    public function __construct(
        public ?string $title,
        public int $courseId,
        public int $examTypeId,
        public ?int $intakeId,
        public ?CarbonImmutable $startsAt,
        public int $durationMinutes,
        public float $totalMarks,
        public float $passPercentage,
        public bool $negativeMarking,
        public ?float $negativeFraction,
        public ?string $instructions,
        public ?CarbonImmutable $closesAt = null,
        public ?string $sharedPin = null,
        public bool $showResult = false,
    ) {}
}
