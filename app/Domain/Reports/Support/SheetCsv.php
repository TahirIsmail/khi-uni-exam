<?php

namespace App\Domain\Reports\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The tabulation sheet as a CSV the office can open in Excel: one row per candidate, one pair of
 * columns per course (marks and grade), then the totals.
 *
 * Streamed rather than built in memory, and written with fputcsv so quotes and commas in a name
 * look after themselves. No package is needed for this.
 */
final class SheetCsv
{
    /**
     * @param  array{calendarType: string|null, courses: list<array<string, mixed>>, candidates: list<array<string, mixed>>}  $sheet
     */
    public function stream(array $sheet, string $filename): StreamedResponse
    {
        $semester = $sheet['calendarType'] === 'semester';

        return response()->streamDownload(function () use ($sheet, $semester): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            $header = ['Candidate', 'Name', 'Roll no'];
            foreach ($sheet['courses'] as $course) {
                $header[] = $course['code'].' marks';
                $header[] = $course['code'].' grade';
            }
            $header[] = 'Obtained';
            $header[] = 'Total';
            $header[] = 'Percentage';
            if ($semester) {
                $header[] = 'Credit hours';
                $header[] = 'GPA';
            }
            $header[] = 'Result';
            $header[] = 'Position';

            fputcsv($handle, $header);

            foreach ($sheet['candidates'] as $candidate) {
                $row = [$candidate['candidateNo'], $candidate['name'], $candidate['rollNo'] ?? ''];

                foreach ($sheet['courses'] as $course) {
                    $result = $candidate['courses'][$course['examinationId']] ?? null;
                    $row[] = $result['totalMarks'] ?? '';
                    $row[] = $result['grade'] ?? '';
                }

                $row[] = $candidate['obtainedMarks'];
                $row[] = $candidate['possibleMarks'];
                $row[] = $candidate['percentage'] ?? '';
                if ($semester) {
                    $row[] = $candidate['creditHours'] ?? '';
                    $row[] = $candidate['gpa'] ?? '';
                }
                $row[] = $candidate['satEverything'] ? ($candidate['isPass'] ? 'Pass' : 'Fail') : 'Incomplete';
                $row[] = $candidate['position'] ?? '';

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
