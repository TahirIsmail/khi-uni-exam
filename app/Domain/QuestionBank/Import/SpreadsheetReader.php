<?php

namespace App\Domain\QuestionBank\Import;

use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads an uploaded spreadsheet into rows of plain strings: CSV and TSV directly, Excel files
 * through PhpSpreadsheet. The first row is the header; its names are matched loosely (case, spaces
 * and a few common alternatives), so a file exported from Excel or Google Sheets works as it is.
 *
 * KMU's own format puts each option of a question on a line of its own under the question
 * (Question No, exam_type, Academic Year, subject, discipline, stem, lead_in, options, correct
 * option). Such a line — nothing in it but an option — is joined to the question above it, so the
 * rest of the import sees one row per question either way.
 */
final class SpreadsheetReader
{
    /** Header name in the file => the name used here. */
    private const ALIASES = [
        'question_type' => 'type',
        'question type' => 'type',
        'type_of_question' => 'type',
        'course_id' => 'course',
        'course_code' => 'course',
        'topic_code' => 'topic',
        'topic_name' => 'topic',
        'node' => 'topic',
        'question_no' => 'number',
        'question_number' => 'number',
        'q_no' => 'number',
        'no' => 'number',
        'sr_no' => 'number',
        's_no' => 'number',
        'academic_year' => 'session',
        'academic_session' => 'session',
        'intake' => 'session',
        'programme' => 'program',
        'academic_program' => 'program',
        'examination' => 'exam_type',
        'examination_type' => 'exam_type',
        'exam' => 'exam_type',
        'exam type' => 'exam_type',
        'clinical_scenario' => 'vignette',
        'scenario' => 'vignette',
        'question' => 'stem',
        'question_text' => 'stem',
        'stem_text' => 'stem',
        'lead-in' => 'lead_in',
        'leadin' => 'lead_in',
        'answer' => 'correct',
        'answer_key' => 'correct',
        'key' => 'correct',
        'correct_option' => 'correct',
        'correct_options' => 'correct',
        'accepted_answers' => 'answers',
        'statements' => 'items',
        'parts' => 'items',
        'blanks' => 'items',
        'steps' => 'items',
        'cognitive_level' => 'cognitive',
        'bloom' => 'cognitive',
        'difficulty_level' => 'difficulty',
        'reference' => 'references',
        'tag' => 'tags',
        'explanation_text' => 'explanation',
        'negative_mark' => 'negative_marks',
        'negative' => 'negative_marks',
        'mark' => 'marks',
    ];

    /** The columns of KMU's format, as the downloadable template names them. */
    public const KMU_COLUMNS = [
        'Question No', 'exam_type', 'Academic Year', 'subject', 'discipline', 'stem', 'lead_in', 'options', 'correct option',
    ];

    /**
     * Every column the import understands, in the order of the all-columns template; KMU's format
     * uses a few of them under its own names (number, session, subject, discipline = program).
     */
    public const COLUMNS = [
        'type', 'exam_type', 'course', 'topic', 'discipline', 'vignette', 'stem', 'lead_in', 'explanation',
        'marks', 'negative_marks', 'cognitive', 'difficulty', 'options', 'correct', 'answers',
        'items', 'references', 'tags', 'number', 'session', 'program', 'subject',
    ];

    /**
     * @return list<array{row_number: int, values: array<string, string>}>
     *
     * @throws RuntimeException when the file cannot be read or has no usable header
     */
    public function read(string $path, string $format): array
    {
        $rows = $format === 'csv' || $format === 'tsv' ? $this->readSeparated($path, $format) : $this->readExcel($path);
        if ($rows === []) {
            throw new RuntimeException('The file is empty.');
        }

        $header = array_map($this->normaliseHeader(...), array_shift($rows));
        if (! in_array('stem', $header, true)) {
            throw new RuntimeException('The first row must name the columns, and one of them must be "stem" (the question text).');
        }

        $read = [];
        foreach ($rows as $index => $cells) {
            $values = [];
            foreach ($header as $position => $name) {
                if ($name === '') {
                    continue;
                }
                $values[$name] = trim((string) ($cells[$position] ?? ''));
            }

            // A line with nothing in it is skipped rather than reported as broken.
            if (implode('', $values) === '') {
                continue;
            }

            // An option on a line of its own belongs to the question above it.
            if ($read !== [] && $this->isOptionLine($values)) {
                $last = &$read[count($read) - 1]['values'];
                $last['options'] = trim(($last['options'] ?? '') === '' ? $values['options'] : $last['options'].' | '.$values['options']);
                if (($last['correct'] ?? '') === '' && ($values['correct'] ?? '') !== '') {
                    $last['correct'] = $values['correct'];
                }
                unset($last);

                continue;
            }

            $read[] = ['row_number' => $index + 2, 'values' => $values];
        }

        return $read;
    }

    /**
     * A line that only carries an option (and perhaps the answer): no number, no question text.
     *
     * @param  array<string, string>  $values
     */
    private function isOptionLine(array $values): bool
    {
        if (($values['options'] ?? '') === '') {
            return false;
        }

        foreach ($values as $name => $value) {
            if ($value !== '' && ! in_array($name, ['options', 'correct'], true)) {
                return false;
            }
        }

        return true;
    }

    private function normaliseHeader(mixed $name): string
    {
        $clean = mb_strtolower(trim((string) $name));
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $underscored = str_replace([' ', '-'], '_', $clean);

        return self::ALIASES[$clean] ?? self::ALIASES[$underscored] ?? $underscored;
    }

    /**
     * @return list<list<string>>
     */
    private function readSeparated(string $path, string $format): array
    {
        $handle = fopen($path, 'r');
        if ($handle === false) {
            throw new RuntimeException('The file could not be opened.');
        }

        $separator = $format === 'tsv' ? "\t" : ',';
        $rows = [];
        while (($cells = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            $rows[] = array_map(fn (mixed $cell): string => (string) $cell, $cells);
        }
        fclose($handle);

        // A byte-order mark from Excel would otherwise become part of the first header name.
        if (isset($rows[0][0])) {
            $rows[0][0] = preg_replace('/^\x{FEFF}/u', '', $rows[0][0]) ?? $rows[0][0];
        }

        return $rows;
    }

    /**
     * @return list<list<string>>
     */
    private function readExcel(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = [];

        foreach ($sheet->toArray(null, true, false, false) as $cells) {
            $rows[] = array_values(array_map(fn (mixed $cell): string => trim((string) $cell), $cells));
        }

        return $rows;
    }
}
