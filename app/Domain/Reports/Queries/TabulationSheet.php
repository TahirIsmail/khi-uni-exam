<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ResultPublication;
use App\Domain\Results\Support\GradeScales;
use App\Support\Cms\CmsAcademic;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The class tabulation sheet: every candidate of a cohort down the side, every course across the
 * top, and what each of them scored.
 *
 * Two things are deliberate here:
 *
 * - **Only published examinations count.** One still being marked or awaiting approval is listed as
 *   awaited, never as marks, so a sheet cannot show a result the controller has not released.
 * - **A candidate number is a student.** Nothing in this module records a student across
 *   examinations, so the roster's candidate number is what joins them. Where two rosters give the
 *   same number a different CNIC, the candidate is flagged rather than quietly merged.
 *
 * The aggregate differs by programme: an annual one (MBBS, BDS) totals marks and gives a
 * percentage; a semester one (DPT) works out a GPA from credit hours, and refuses to if any course
 * that should count has no credit hours recorded.
 */
final class TabulationSheet
{
    /** @var array<int, float> each examination's pass mark, read once for the whole sheet */
    private array $passPercentages = [];

    public function __construct(
        private readonly CmsAcademic $academic,
        private readonly CourseComponents $components,
        private readonly GradeScales $scales,
    ) {}

    /**
     * @return array{
     *     calendarType: string|null,
     *     courses: list<array<string, mixed>>,
     *     candidates: list<array<string, mixed>>,
     *     awaiting: list<array<string, mixed>>,
     *     creditHoursMissing: list<string>,
     * }
     */
    public function for(CohortKey $cohort): array
    {
        $examinations = $this->examinationsOf($cohort);
        $published = $this->publishedIds($examinations);

        $this->components->load($published);
        $this->passPercentages = $examinations->pluck('pass_percentage', 'id')
            ->map(fn (mixed $p): float => (float) $p)->all();

        $calendarType = $this->academic->programmeCalendar($cohort->programmeId);
        $courses = $this->coursesOf($examinations->whereIn('id', $published), $calendarType);
        $creditHoursMissing = array_values(array_map(
            fn (array $course): string => $course['code'],
            array_filter($courses, fn (array $course): bool => $course['creditHours'] === null)
        ));

        $rows = $this->candidateRows($examinations->whereIn('id', $published), $courses, $calendarType, $creditHoursMissing === []);

        return [
            'calendarType' => $calendarType,
            'courses' => $courses,
            'candidates' => $this->ranked($rows, $calendarType),
            'awaiting' => array_values($examinations->whereNotIn('id', $published)
                ->map(fn (Examination $exam): array => [
                    'reference' => $exam->public_ref,
                    'title' => $exam->title,
                ])->all()),
            'creditHoursMissing' => $calendarType === 'semester' ? $creditHoursMissing : [],
        ];
    }

    /**
     * @return Collection<int, Examination>
     */
    private function examinationsOf(CohortKey $cohort): Collection
    {
        $query = Examination::query()
            ->where('branch_id', $cohort->branchId)
            ->where('programme_id', $cohort->programmeId)
            ->where('professional_id', $cohort->professionalId);

        // An examination need not name an intake; a cohort without one is those examinations.
        $cohort->intakeId === null
            ? $query->whereNull('intake_id')
            : $query->where('intake_id', $cohort->intakeId);

        if ($cohort->termId !== null) {
            $query->where('term_id', $cohort->termId);
        }

        return $query->orderBy('id')->get();
    }

    /**
     * @param  Collection<int, Examination>  $examinations
     * @return list<int>
     */
    private function publishedIds(Collection $examinations): array
    {
        return array_values(ResultPublication::query()
            ->whereIn('examination_id', $examinations->pluck('id'))
            ->where('status', PublicationStatus::Published->value)
            ->pluck('examination_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all());
    }

    /**
     * @param  Collection<int, Examination>  $examinations
     * @return list<array<string, mixed>>
     */
    private function coursesOf(Collection $examinations, ?string $calendarType): array
    {
        $creditHours = DB::connection('cms')->table('v_cms_courses')
            ->whereIn('id', $examinations->pluck('course_id')->filter()->unique())
            ->get(['id', 'course_code', 'title', 'credit_hours'])
            ->keyBy('id');

        return array_values($examinations->map(function (Examination $exam) use ($creditHours, $calendarType): array {
            $course = $creditHours->get($exam->course_id);
            $parts = $this->components->of($exam->id);

            return [
                'examinationId' => $exam->id,
                'reference' => $exam->public_ref,
                'code' => $course->course_code ?? ('#'.$exam->course_id),
                'title' => $course->title ?? $exam->title,
                // Where a subject is made of several parts, what it is out of is all of them
                // together — the paper alone is no longer the course's marks.
                'totalMarks' => $parts === []
                    ? (float) $exam->total_marks
                    : round(array_sum(array_map(fn ($part): float => $part->max_marks, $parts)), 2),
                'passPercentage' => (float) $exam->pass_percentage,
                'components' => array_map(fn ($part): array => [
                    'code' => $part->code,
                    'name' => $part->name,
                    'maxMarks' => $part->max_marks,
                    'group' => $part->group,
                ], $parts),
                // Credit hours only matter where a GPA is worked out from them.
                'creditHours' => $calendarType === 'semester'
                    ? (($course->credit_hours ?? null) === null ? null : (float) $course->credit_hours)
                    : null,
            ];
        })->all());
    }

    /**
     * @param  Collection<int, Examination>  $examinations
     * @param  list<array<string, mixed>>  $courses
     * @return list<array<string, mixed>>
     */
    private function candidateRows(Collection $examinations, array $courses, ?string $calendarType, bool $creditHoursComplete): array
    {
        if ($examinations->isEmpty()) {
            return [];
        }

        // Left joins throughout: somebody on the roster who never sat still belongs on the sheet,
        // as an incomplete row rather than as an absence from it.
        $sat = DB::table('cand_candidates as c')
            ->leftJoin('cand_candidate_exams as a', 'a.candidate_id', '=', 'c.id')
            ->leftJoin('exm_results as r', 'r.candidate_exam_id', '=', 'a.id')
            ->whereIn('c.examination_id', $examinations->pluck('id'))
            ->get([
                'c.id as candidate_id', 'c.examination_id', 'c.candidate_no', 'c.name', 'c.roll_no', 'c.cnic',
                'r.total_marks', 'r.percentage', 'r.grade', 'r.grade_point', 'r.is_pass', 'r.pending_items',
            ]);

        /** @var array<string, array<string, mixed>> $byCandidate */
        $byCandidate = [];

        foreach ($sat as $row) {
            /** @var stdClass $row */
            $number = (string) $row->candidate_no;

            $byCandidate[$number] ??= [
                'candidateNo' => $number,
                'name' => (string) $row->name,
                'rollNo' => $row->roll_no === null ? null : (string) $row->roll_no,
                'cnics' => [],
                'courses' => [],
            ];

            if ($row->cnic !== null && $row->cnic !== '') {
                $byCandidate[$number]['cnics'][(string) $row->cnic] = true;
            }

            $byCandidate[$number]['courses'][(int) $row->examination_id] = $this->courseResult($row, $calendarType);
        }

        ksort($byCandidate);

        return array_values(array_map(
            fn (array $candidate): array => $this->summarise($candidate, $courses, $calendarType, $creditHoursComplete),
            $byCandidate
        ));
    }

    /**
     * What one candidate got for one course.
     *
     * Without components that is what the paper's marking produced, exactly as it always was. With
     * them, the paper is one part of a subject that also has a practical, a viva and an internal
     * assessment, so the marks, the percentage, the grade and the pass are all the subject's rather
     * than the paper's — and a part nobody has entered yet leaves the subject incomplete rather than
     * quietly scoring it nil.
     *
     * @return array<string, mixed>
     */
    private function courseResult(stdClass $row, ?string $calendarType): array
    {
        $examinationId = (int) $row->examination_id;
        $paperMarks = $row->total_marks === null || (bool) ($row->pending_items ?? false)
            ? null
            : (float) $row->total_marks;

        if (! $this->components->has($examinationId)) {
            return [
                'totalMarks' => $row->total_marks === null ? null : (float) $row->total_marks,
                'percentage' => $row->percentage === null ? null : (float) $row->percentage,
                'grade' => $row->grade === null ? null : (string) $row->grade,
                'gradePoint' => $row->grade_point === null ? null : (float) $row->grade_point,
                'isPass' => $row->is_pass === null ? null : (bool) $row->is_pass,
                'pending' => (bool) ($row->pending_items ?? false),
                'parts' => [],
                'failedGroups' => [],
            ];
        }

        $subject = $this->components->resultFor($examinationId, (int) $row->candidate_id, $paperMarks);
        $awarded = $subject['complete'] && $calendarType !== null && $subject['percentage'] !== null
            ? $this->scales->award($calendarType, $subject['percentage'])
            : null;

        return [
            'totalMarks' => $subject['complete'] ? $subject['obtained'] : null,
            'percentage' => $subject['complete'] ? $subject['percentage'] : null,
            'grade' => $awarded['grade'] ?? null,
            'gradePoint' => $awarded['point'] ?? null,
            // Both bars: the subject's own pass mark, and each half passed on its own.
            'isPass' => $subject['complete']
                && $subject['isPass']
                && $subject['percentage'] >= $this->passPercentage($examinationId),
            'pending' => ! $subject['complete'],
            'parts' => $subject['parts'],
            'failedGroups' => $subject['failedGroups'],
        ];
    }

    private function passPercentage(int $examinationId): float
    {
        return $this->passPercentages[$examinationId] ?? 0.0;
    }

    /**
     * @param  array<string, mixed>  $candidate
     * @param  list<array<string, mixed>>  $courses
     * @return array<string, mixed>
     */
    private function summarise(array $candidate, array $courses, ?string $calendarType, bool $creditHoursComplete): array
    {
        /** @var array<int, array<string, mixed>> $taken */
        $taken = $candidate['courses'];

        $obtained = 0.0;
        $possible = 0.0;
        $qualityPoints = 0.0;
        $creditHours = 0.0;
        $passedEverything = true;
        $satEverything = true;

        foreach ($courses as $course) {
            $result = $taken[$course['examinationId']] ?? null;

            if ($result === null || $result['totalMarks'] === null) {
                $satEverything = false;
                $passedEverything = false;

                continue;
            }

            $obtained += $result['totalMarks'];
            $possible += $course['totalMarks'];
            $passedEverything = $passedEverything && $result['isPass'] === true;

            if ($course['creditHours'] !== null && $result['gradePoint'] !== null) {
                $qualityPoints += $result['gradePoint'] * $course['creditHours'];
                $creditHours += $course['creditHours'];
            }
        }

        $percentage = $possible > 0 ? round(($obtained / $possible) * 100, 2) : null;

        return [
            'candidateNo' => $candidate['candidateNo'],
            'name' => $candidate['name'],
            'rollNo' => $candidate['rollNo'],
            // Two rosters giving this number different people is a data problem, not a result.
            'identityClash' => count($candidate['cnics']) > 1,
            'courses' => $taken,
            'obtainedMarks' => round($obtained, 2),
            'possibleMarks' => round($possible, 2),
            'percentage' => $percentage,
            'gpa' => $calendarType === 'semester' && $creditHoursComplete && $creditHours > 0
                ? round($qualityPoints / $creditHours, 2)
                : null,
            'creditHours' => $creditHours > 0 ? round($creditHours, 1) : null,
            'satEverything' => $satEverything,
            'isPass' => $satEverything && $passedEverything,
        ];
    }

    /**
     * Position by the figure that programme is judged on, with a tie sharing a place.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function ranked(array $rows, ?string $calendarType): array
    {
        $key = $calendarType === 'semester' ? 'gpa' : 'percentage';

        $ordered = collect($rows)->sortByDesc(fn (array $row): float => (float) ($row[$key] ?? 0))->values();

        $position = 0;
        $seen = 0;
        $previous = null;

        return array_values($ordered->map(function (array $row) use ($key, &$position, &$seen, &$previous): array {
            $seen++;
            $value = $row[$key];

            if ($value !== $previous) {
                $position = $seen;
                $previous = $value;
            }

            $row['position'] = $value === null ? null : $position;

            return $row;
        })->all());
    }
}
