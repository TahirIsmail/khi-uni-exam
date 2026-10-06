<?php

namespace App\Domain\Exam;

use App\Support\Cms\CmsAcademic;
use Carbon\CarbonInterface;

/**
 * The title an examination gets when nobody writes one: the way the university names it — "MBBS
 * First Professional Annual Examination 2026 — MBBS-1-FND Foundation Module".
 */
final class ExaminationTitle
{
    public function __construct(private readonly CmsAcademic $academic) {}

    public function for(int $branchId, int $programmeId, int $professionalId, ?int $termId, int $examTypeId, string $courseLabel, CarbonInterface $when): string
    {
        $programme = collect($this->academic->programmes($branchId))->firstWhere('id', $programmeId)['name'] ?? '';
        $year = $this->academic->yearName($branchId, $professionalId, $termId) ?? '';
        $type = collect($this->academic->examTypes())->firstWhere('id', $examTypeId)['name'] ?? '';

        $head = trim(implode(' ', array_filter([$programme, $year, $type, 'Examination', $when->format('Y')])));

        return mb_substr($head.' — '.$courseLabel, 0, 200);
    }
}
