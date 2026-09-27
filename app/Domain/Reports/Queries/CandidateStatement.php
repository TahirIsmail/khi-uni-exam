<?php

namespace App\Domain\Reports\Queries;

use App\Support\Cms\CmsAcademic;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One candidate's detailed marks certificate: what they scored in every course of the cohort, and —
 * for a semester programme — the GPA of this term and the CGPA of every term so far.
 *
 * It is built from the same TabulationSheet the class sheet uses, so a candidate's row on the sheet
 * and their own certificate can never disagree.
 */
final class CandidateStatement
{
    public function __construct(
        private readonly TabulationSheet $sheet,
        private readonly CmsAcademic $academic,
    ) {}

    /**
     * @return array<string, mixed>|null null when nobody of that number sat anything in this cohort
     */
    public function for(CohortKey $cohort, string $candidateNo): ?array
    {
        $sheet = $this->sheet->for($cohort);

        $candidate = collect($sheet['candidates'])->firstWhere('candidateNo', $candidateNo);

        if ($candidate === null) {
            return null;
        }

        return [
            'candidate' => $candidate,
            'courses' => $sheet['courses'],
            'calendarType' => $sheet['calendarType'],
            'awaiting' => $sheet['awaiting'],
            'creditHoursMissing' => $sheet['creditHoursMissing'],
            'cumulative' => $sheet['calendarType'] === 'semester'
                ? $this->cumulative($cohort, $candidateNo)
                : null,
            'place' => [
                'programme' => $this->programmeName($cohort->programmeId),
                'year' => $this->academic->yearName($cohort->branchId, $cohort->professionalId, $cohort->termId),
                'intake' => $this->intakeName($cohort),
            ],
        ];
    }

    /**
     * The CGPA: every term of this programme and intake that this candidate number has a published
     * result in, this one included. Null while any contributing term cannot work out a GPA — a
     * cumulative figure built on a gap would be worse than none.
     *
     * @return array{cgpa: float|null, creditHours: float, terms: list<array{termId: int|null, name: string|null, gpa: float|null, creditHours: float|null}>}
     */
    private function cumulative(CohortKey $cohort, string $candidateNo): array
    {
        $terms = [];
        $qualityPoints = 0.0;
        $creditHours = 0.0;
        $complete = true;

        foreach ($this->termsOf($cohort) as $termId) {
            $termSheet = $this->sheet->for($cohort->withTerm($termId));
            $row = collect($termSheet['candidates'])->firstWhere('candidateNo', $candidateNo);

            if ($row === null) {
                continue;
            }

            $terms[] = [
                'termId' => $termId,
                'name' => $this->academic->yearName($cohort->branchId, $cohort->professionalId, $termId),
                'gpa' => $row['gpa'],
                'creditHours' => $row['creditHours'],
            ];

            if ($row['gpa'] === null || $row['creditHours'] === null) {
                $complete = false;

                continue;
            }

            $qualityPoints += $row['gpa'] * $row['creditHours'];
            $creditHours += $row['creditHours'];
        }

        return [
            'cgpa' => $complete && $creditHours > 0 ? round($qualityPoints / $creditHours, 2) : null,
            'creditHours' => round($creditHours, 1),
            'terms' => $terms,
        ];
    }

    /**
     * Every term this programme and intake has held an examination in, oldest first.
     *
     * @return list<int|null>
     */
    private function termsOf(CohortKey $cohort): array
    {
        return array_values(DB::table('exm_examinations')
            ->where('branch_id', $cohort->branchId)
            ->where('programme_id', $cohort->programmeId)
            ->where('professional_id', $cohort->professionalId)
            ->where('intake_id', $cohort->intakeId)
            ->distinct()
            ->orderBy('term_id')
            ->pluck('term_id')
            ->map(fn (mixed $id): ?int => $id === null ? null : (int) $id)
            ->all());
    }

    private function programmeName(int $programmeId): ?string
    {
        $row = DB::connection('cms')->table('v_cms_programmes')->where('id', $programmeId)->first(['name']);

        return $row === null ? null : (string) $row->name;
    }

    private function intakeName(CohortKey $cohort): ?string
    {
        /** @var stdClass|null $row */
        $row = DB::connection('cms')->table('v_cms_intakes')
            ->where('id', $cohort->intakeId)->first(['name']);

        return $row === null ? null : (string) $row->name;
    }
}
