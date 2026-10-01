<?php

namespace App\Domain\Analytics\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Results\Models\Result;

/**
 * Overall examination statistics (KMU's post-hoc categories): number of students, total marks,
 * mean, median, standard deviation, minimum, maximum, pass and fail percentages.
 *
 * Read from the same compiled results (Result) that the result sheets show, so the two always
 * agree: a candidate's score is their total after any negative marking, and pass or fail is the
 * result's own, against the examination's pass percentage. Attempts still being marked are left out
 * and counted separately.
 */
final class ExamStatistics
{
    /**
     * @return array{students: int, pending: int, totalMarks: float, mean: ?float, median: ?float, sd: ?float, min: ?float, max: ?float, passPercent: ?float, failPercent: ?float, passed: int, failed: int, passMark: float}
     */
    public function forExamination(Examination $examination): array
    {
        $attemptIds = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->pluck('id');

        $results = Result::query()->whereIn('candidate_exam_id', $attemptIds)->get();
        $complete = $results->filter(fn (Result $result): bool => ! $result->pending_items);

        $scores = $complete->map(fn (Result $result): float => (float) $result->total_marks)->sort()->values()->all();
        $n = count($scores);
        $passed = $complete->filter(fn (Result $result): bool => $result->is_pass)->count();

        $mean = $n === 0 ? null : array_sum($scores) / $n;

        return [
            'students' => $n,
            'pending' => $attemptIds->count() - $n,
            'totalMarks' => (float) $examination->total_marks,
            'mean' => $mean === null ? null : round($mean, 2),
            'median' => $n === 0 ? null : round($n % 2 === 1 ? $scores[intdiv($n, 2)] : ($scores[$n / 2 - 1] + $scores[$n / 2]) / 2, 2),
            // The spread of the scores themselves (population standard deviation), as reliability uses.
            'sd' => $n === 0 ? null : round(sqrt(array_sum(array_map(fn (float $s): float => ($s - $mean) ** 2, $scores)) / $n), 2),
            'min' => $n === 0 ? null : $scores[0],
            'max' => $n === 0 ? null : $scores[$n - 1],
            'passed' => $passed,
            'failed' => $n - $passed,
            'passPercent' => $n === 0 ? null : round($passed / $n * 100, 1),
            'failPercent' => $n === 0 ? null : round(($n - $passed) / $n * 100, 1),
            'passMark' => (float) $examination->pass_percentage,
        ];
    }
}
