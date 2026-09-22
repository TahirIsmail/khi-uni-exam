<?php

namespace App\Domain\Candidate\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Candidate\Enums\CandidateStatus;
use App\Domain\Candidate\Models\Candidate;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reads a candidate list from a CSV file and adds it to an examination's roster (exam-phase.md's
 * settled decision: candidates are a list imported for each examination, not read from a student
 * register). A row with a candidate number already on this examination's roster is skipped, not
 * overwritten — importing the same file twice, or a corrected file with new rows added, is safe.
 */
final class ImportCandidates
{
    private const MAX_ROWS = 5000;

    /** Header name in the file => the column used here. */
    private const ALIASES = [
        'candidate_number' => 'candidate_no',
        'candidate no' => 'candidate_no',
        'roll_number' => 'roll_no',
        'roll no' => 'roll_no',
        'registration_no' => 'roll_no',
        'reg_no' => 'roll_no',
        'national_id' => 'cnic',
        'nic' => 'cnic',
        'full_name' => 'name',
        'candidate_name' => 'name',
        'phone_number' => 'phone',
        'mobile' => 'phone',
    ];

    public function __construct(
        private readonly CandidateGuard $guard,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{imported: int, skipped: int, errors: list<array{row: int, message: string}>}
     */
    public function __invoke(User $user, Examination $examination, UploadedFile $file): array
    {
        $this->guard->authorise($user, $examination, 'candidate.manage', 'You cannot register candidates for this course.');

        $rows = $this->read($file);
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.count($rows).' candidates; please split it into files of at most '.self::MAX_ROWS.'.']);
        }

        $existing = Candidate::query()->where('examination_id', $examination->id)->pluck('candidate_no')->all();
        $known = array_flip($existing);
        $seenInFile = [];
        $toInsert = [];
        $errors = [];

        foreach ($rows as $row) {
            $error = $this->rowError($row['values'], $known, $seenInFile);
            if ($error !== null) {
                $errors[] = ['row' => $row['row_number'], 'message' => $error];

                continue;
            }

            $candidateNo = trim($row['values']['candidate_no']);
            $seenInFile[$candidateNo] = true;
            $toInsert[] = [
                'examination_id' => $examination->id,
                'branch_id' => $examination->branch_id,
                'candidate_no' => $candidateNo,
                'name' => trim($row['values']['name']),
                'roll_no' => $this->cell($row['values'], 'roll_no'),
                'cnic' => $this->cell($row['values'], 'cnic'),
                'email' => $this->cell($row['values'], 'email'),
                'phone' => $this->cell($row['values'], 'phone'),
                'status' => CandidateStatus::Enrolled->value,
                'created_by' => $user->id,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($toInsert !== []) {
            DB::transaction(function () use ($toInsert, $examination, $user): void {
                foreach (array_chunk($toInsert, 500) as $chunk) {
                    Candidate::query()->insert($chunk);
                }
                $this->audit->record('candidate.imported', 'examination', $examination->id, null, ['count' => count($toInsert)], null, $user, $examination->branch_id);
            });
        }

        return ['imported' => count($toInsert), 'skipped' => count($errors), 'errors' => array_slice($errors, 0, 100)];
    }

    /**
     * @param  array<string, string>  $values
     * @param  array<string, int>  $known
     * @param  array<string, bool>  $seenInFile
     */
    private function rowError(array $values, array $known, array $seenInFile): ?string
    {
        $candidateNo = trim($values['candidate_no'] ?? '');
        $name = trim($values['name'] ?? '');

        if ($candidateNo === '') {
            return 'Missing the candidate number.';
        }
        if (mb_strlen($candidateNo) > 30) {
            return 'The candidate number is too long (at most 30 characters).';
        }
        if ($name === '') {
            return 'Missing the candidate\'s name.';
        }
        if (mb_strlen($name) > 150) {
            return 'The name is too long (at most 150 characters).';
        }
        if (isset($known[$candidateNo])) {
            return "Candidate {$candidateNo} is already on this examination's roster.";
        }
        if (isset($seenInFile[$candidateNo])) {
            return "Candidate {$candidateNo} appears twice in this file.";
        }
        $email = trim($values['email'] ?? '');
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'The email address is not valid.';
        }

        return null;
    }

    /**
     * @param  array<string, string>  $values
     */
    private function cell(array $values, string $key): ?string
    {
        $value = trim($values[$key] ?? '');

        return $value === '' ? null : mb_substr($value, 0, $key === 'email' ? 190 : 60);
    }

    /**
     * @return list<array{row_number: int, values: array<string, string>}>
     */
    private function read(UploadedFile $file): array
    {
        $handle = fopen($file->getRealPath(), 'r');
        if ($handle === false) {
            throw ValidationException::withMessages(['file' => 'The file could not be opened.']);
        }

        $lines = [];
        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $lines[] = array_map(fn (mixed $cell): string => (string) $cell, $cells);
        }
        fclose($handle);

        if ($lines === []) {
            throw ValidationException::withMessages(['file' => 'The file is empty.']);
        }
        // A byte-order mark from Excel would otherwise become part of the first header name.
        $lines[0][0] = preg_replace('/^\x{FEFF}/u', '', $lines[0][0]) ?? $lines[0][0];

        $header = array_map($this->normaliseHeader(...), array_shift($lines));
        if (! in_array('candidate_no', $header, true) || ! in_array('name', $header, true)) {
            throw ValidationException::withMessages(['file' => 'The first row must name the columns, and must include "candidate_no" and "name".']);
        }

        $rows = [];
        foreach ($lines as $index => $cells) {
            $values = [];
            foreach ($header as $position => $columnName) {
                if ($columnName === '') {
                    continue;
                }
                $values[$columnName] = trim((string) ($cells[$position] ?? ''));
            }
            if (implode('', $values) === '') {
                continue;
            }
            $rows[] = ['row_number' => $index + 2, 'values' => $values];
        }

        return $rows;
    }

    private function normaliseHeader(mixed $name): string
    {
        $clean = mb_strtolower(trim((string) $name));
        $clean = (string) preg_replace('/\s+/', ' ', $clean);
        $underscored = str_replace([' ', '-'], '_', $clean);

        return self::ALIASES[$clean] ?? self::ALIASES[$underscored] ?? $underscored;
    }
}
