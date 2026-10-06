<?php

namespace App\Domain\Results\Support;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The grading scales in exm_grade_scales: the bands a percentage falls into, highest first, so the
 * first band it reaches is the grade.
 *
 * Two scales exist because KMU runs two kinds of programme — an annual MBBS or BDS result is marks
 * and a distinction, a semester DPT result carries the grade point its GPA is worked out from.
 *
 * One instance per request (see AppServiceProvider), so the bands are read once and a scale edited
 * mid-request is picked up by forget() rather than living on in static state.
 */
final class GradeScales
{
    /** @var array<string, list<array{min: float, grade: string, point: ?float, remark: string}>> */
    private array $cache = [];

    /**
     * The grade a percentage earns under one calendar type. Null when no scale has been set up for
     * it, which is what an empty table means — a result then simply carries no grade.
     *
     * @return array{grade: string, point: ?float, remark: string}|null
     */
    public function award(string $calendarType, float $percentage): ?array
    {
        foreach ($this->bands($calendarType) as $band) {
            if ($percentage >= $band['min']) {
                return ['grade' => $band['grade'], 'point' => $band['point'], 'remark' => $band['remark']];
            }
        }

        return null;
    }

    public function isEmpty(string $calendarType): bool
    {
        return $this->bands($calendarType) === [];
    }

    /** Drops what has been read, for when the scale itself changes inside one request or test. */
    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * @return list<array{min: float, grade: string, point: ?float, remark: string}>
     */
    private function bands(string $calendarType): array
    {
        return $this->cache[$calendarType] ??= array_values(
            DB::table('exm_grade_scales')
                ->where('calendar_type', $calendarType)
                ->orderByDesc('min_percentage')
                ->get(['min_percentage', 'grade', 'grade_point', 'remark'])
                ->map(fn (stdClass $row): array => [
                    'min' => (float) $row->min_percentage,
                    'grade' => (string) $row->grade,
                    'point' => $row->grade_point === null ? null : (float) $row->grade_point,
                    'remark' => (string) $row->remark,
                ])
                ->all()
        );
    }
}
