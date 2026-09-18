<?php

namespace App\Domain\QuestionBank\Import;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\ImportRow;
use App\Domain\QuestionBank\Models\QuestionImport;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Validation\QuestionContent;
use App\Domain\QuestionBank\Validation\QuestionValidator;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use App\Support\Html\QuestionHtml;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Reads an uploaded file and checks every line, without touching the question bank (blueprint 13).
 * The result is a list of rows with what each would create and what is wrong with it, so the author
 * decides whether to commit.
 *
 * Checks per row: the type's own rules (the same QuestionValidator the editor uses), the campus and
 * exam access of the course, and whether the question already exists — in the bank or twice in the
 * same file.
 */
final class CheckImport
{
    private const MAX_ROWS = 2000;

    public function __construct(
        private readonly SpreadsheetReader $reader,
        private readonly RowParser $parser,
        private readonly QuestionValidator $validator,
        private readonly AccessControl $access,
        private readonly CmsAcademic $academic,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{course_id?: int|null, node_id?: int|null, type_id?: int|null}  $defaults
     */
    public function __invoke(User $user, int $branchId, UploadedFile $file, array $defaults = []): QuestionImport
    {
        $format = mb_strtolower($file->getClientOriginalExtension() ?: 'csv');
        $format = in_array($format, ['csv', 'tsv', 'txt'], true) ? ($format === 'txt' ? 'csv' : $format) : 'xlsx';

        try {
            $rows = $this->reader->read($file->getRealPath(), $format);
        } catch (RuntimeException $problem) {
            throw ValidationException::withMessages(['file' => $problem->getMessage()]);
        }

        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'The file has '.count($rows).' questions; please split it into files of at most '.self::MAX_ROWS.'.']);
        }

        $path = $file->store("qbank/imports/{$branchId}", 'local');

        return DB::transaction(function () use ($user, $branchId, $file, $format, $path, $rows, $defaults): QuestionImport {
            $import = QuestionImport::query()->create([
                'branch_id' => $branchId,
                'original_name' => mb_substr((string) $file->getClientOriginalName(), 0, 255),
                'disk' => 'local',
                'path' => (string) $path,
                'format' => $format,
                'size_bytes' => (int) $file->getSize(),
                'defaults' => $defaults,
                'rows_total' => count($rows),
                'uploaded_by' => $user->id,
            ]);

            $valid = 0;
            $invalid = 0;
            $hashesInFile = [];

            foreach ($rows as $row) {
                ['content' => $content, 'errors' => $errors] = $this->parser->parse($row['values'], $branchId, $defaults);
                $warnings = [];
                $hash = null;
                $status = 'valid';

                if ($content !== null) {
                    $result = $this->validator->check($content);
                    $errors = $result['errors'];
                    $warnings = $result['warnings'];
                    $hash = $content->contentHash();

                    $accessProblem = $this->accessProblem($user, $branchId, $content);
                    if ($accessProblem !== null) {
                        $errors['course'][] = $accessProblem;
                    }

                    if (isset($hashesInFile[$hash])) {
                        $errors['stem'][] = 'The same question is on line '.$hashesInFile[$hash].' of this file.';
                        $status = 'duplicate';
                    } elseif ($this->alreadyInBank($hash, $branchId)) {
                        $warnings[] = 'A question with the same text is already in the bank.';
                    }
                    $hashesInFile[$hash] ??= $row['row_number'];
                }

                if ($errors !== []) {
                    $status = $status === 'duplicate' ? 'duplicate' : 'invalid';
                    $invalid++;
                } else {
                    $valid++;
                }

                ImportRow::query()->create([
                    'import_id' => $import->id,
                    'row_number' => $row['row_number'],
                    'raw' => $row['values'],
                    'parsed' => $content === null ? null : $this->summarise($content),
                    'errors' => $errors === [] ? null : $errors,
                    'warnings' => $warnings === [] ? null : $warnings,
                    'content_hash' => $hash,
                    'status' => $status,
                ]);
            }

            $import->update(['rows_valid' => $valid, 'rows_invalid' => $invalid]);

            $this->audit->record('qbank.import.checked', 'question_import', $import->id, null, [
                'file' => $import->original_name,
                'rows' => $import->rows_total,
                'valid' => $valid,
                'invalid' => $invalid,
            ], null, $user, $branchId);

            return $import;
        });
    }

    private function accessProblem(User $user, int $branchId, QuestionContent $content): ?string
    {
        $place = $this->academic->placeOfNode($content->nodeId, $content->courseId);
        if ($place === null || $place['branch_id'] !== $branchId) {
            return 'That course and topic are not in this campus.';
        }

        return $this->access->allows($user, 'qbank.question.create', new ScopeTarget($branchId, $place['programme_id'], $place['professional_id'], $place['course_id']))
            ? null
            : 'Your exam access does not cover this course.';
    }

    private function alreadyInBank(string $hash, int $branchId): bool
    {
        return QuestionVersion::query()->where('content_hash', $hash)->where('branch_id', $branchId)->exists();
    }

    /**
     * What the row would create, for the preview.
     *
     * @return array<string, mixed>
     */
    private function summarise(QuestionContent $content): array
    {
        return [
            'type' => $content->type->name,
            'course_id' => $content->courseId,
            'node_id' => $content->nodeId,
            'topic' => $this->academic->nodeName($content->nodeId),
            'stem' => mb_substr(QuestionHtml::toText($content->stem), 0, 200),
            'marks' => $content->marks,
            'options' => count($content->options),
            'correct' => count(array_filter($content->options, fn (array $option): bool => (bool) $option['is_correct'])),
            'items' => count($content->items),
            'answers' => count($content->answers),
            'references' => count($content->references),
            'tags' => count($content->tagIds),
        ];
    }
}
