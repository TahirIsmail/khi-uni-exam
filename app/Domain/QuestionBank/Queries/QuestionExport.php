<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\Audit\AuditLogger;
use App\Domain\QuestionBank\Models\QuestionAnswer;
use App\Domain\QuestionBank\Models\QuestionItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionReference;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Tag;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use App\Support\Html\QuestionHtml;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Taking questions out of the bank as a spreadsheet, answer keys and all. It is its own permission
 * (Export Questions & Answer Keys in kmu-cms) because the file leaves the system: only what the
 * search itself would show is exported — the campus and the user's exam access decide that — and
 * every export is written to the audit log.
 *
 * The columns are the ones the import reads, so a file can be exported, edited and brought back in.
 */
final class QuestionExport
{
    /** At most this many questions in one file, so one click cannot drain the bank. */
    public const LIMIT = 5000;

    public const COLUMNS = [
        'reference', 'version', 'status', 'type', 'exam_type', 'course', 'topic', 'discipline', 'vignette',
        'stem', 'lead_in', 'explanation', 'marks', 'negative_marks', 'cognitive', 'difficulty',
        'options', 'correct', 'answers', 'items', 'references', 'tags', 'author', 'times_used',
    ];

    /** @var array<int, string>|null */
    private ?array $cognitiveLevels = null;

    /** @var array<int, string>|null */
    private ?array $difficultyLevels = null;

    /** @var array<int, string>|null */
    private ?array $disciplines = null;

    /** @var array<int, string>|null */
    private ?array $examTypes = null;

    /** @var array<int, string>|null */
    private ?array $authors = null;

    public function __construct(
        private readonly QuestionList $list,
        private readonly CmsAcademic $academic,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function stream(User $user, int $branchId, array $filters): StreamedResponse
    {
        $versionIds = $this->list->versionIdsFor($user, $branchId, $filters, self::LIMIT);

        $this->audit->record('qbank.question.exported', 'question_export', null, null, [
            'questions' => count($versionIds),
            'filters' => array_filter($filters, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== false),
        ], null, $user, $branchId);

        return response()->streamDownload(function () use ($versionIds): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fputcsv($out, self::COLUMNS, escape: '');

            // In chunks, so a large export never holds every question in memory at once.
            foreach (array_chunk($versionIds, 200) as $chunk) {
                $versions = QuestionVersion::query()
                    ->whereIn('id', $chunk)
                    ->with(['question:id,public_ref,times_used', 'type:id,name', 'options', 'items.correctOption', 'answers', 'references', 'tags'])
                    ->orderBy('id')
                    ->get();

                foreach ($versions as $version) {
                    fputcsv($out, $this->line($version), escape: '');
                }
            }

            fclose($out);
        }, 'kmu-questions-'.now()->format('Y-m-d-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * @return list<string>
     */
    private function line(QuestionVersion $version): array
    {
        return [
            $version->question->public_ref,
            (string) $version->version_no,
            $version->status->label(),
            $version->type->name,
            $version->exam_type_id === null ? '' : ($this->examTypes()[$version->exam_type_id] ?? ''),
            $this->academic->courseLabel($version->course_id) ?? ('#'.$version->course_id),
            (string) ($this->academic->nodeName($version->node_id) ?? ''),
            $version->discipline_id === null ? '' : ($this->disciplines()[$version->discipline_id] ?? ''),
            QuestionHtml::toText($version->vignette ?? ''),
            QuestionHtml::toText($version->stem),
            (string) $version->lead_in,
            QuestionHtml::toText($version->explanation ?? ''),
            (string) $version->marks,
            (string) $version->negative_marks,
            $version->cognitive_level_id === null ? '' : ($this->cognitiveLevels()[$version->cognitive_level_id] ?? ''),
            $version->difficulty_level_id === null ? '' : ($this->difficultyLevels()[$version->difficulty_level_id] ?? ''),
            $version->options->map(fn (QuestionOption $option): string => $option->label.') '.QuestionHtml::toText($option->body))->implode(' | '),
            $version->options->filter(fn (QuestionOption $option): bool => $option->is_correct)->map(fn (QuestionOption $option): string => $option->label)->implode(','),
            $version->answers->map(fn (QuestionAnswer $answer): string => $this->answer($answer))->implode(' | '),
            $version->items->map(fn (QuestionItem $item): string => $this->item($item))->implode(' | '),
            $version->references->map(fn (QuestionReference $reference): string => trim($reference->citation.' '.(string) $reference->locator))->implode(' | '),
            $version->tags->map(fn (Tag $tag): string => $tag->name)->implode(', '),
            $this->authors()[$version->author_id] ?? '',
            (string) $version->question->times_used,
        ];
    }

    private function answer(QuestionAnswer $answer): string
    {
        if ($answer->numeric_value !== null) {
            return trim($answer->numeric_value.($answer->tolerance === null ? '' : ' ± '.$answer->tolerance).' '.(string) $answer->unit);
        }

        return (string) $answer->answer_text;
    }

    private function item(QuestionItem $item): string
    {
        $body = QuestionHtml::toText($item->body);

        if ($item->is_true !== null) {
            return $body.' = '.($item->is_true ? 'true' : 'false');
        }
        if ($item->correct_option_id !== null) {
            return $body.' -> '.(string) $item->correctOption?->label;
        }

        return $body;
    }

    /**
     * @return array<int, string>
     */
    private function cognitiveLevels(): array
    {
        return $this->cognitiveLevels ??= $this->names('qb_cognitive_levels');
    }

    /**
     * @return array<int, string>
     */
    private function difficultyLevels(): array
    {
        return $this->difficultyLevels ??= $this->names('qb_difficulty_levels');
    }

    /**
     * @return array<int, string>
     */
    private function disciplines(): array
    {
        if ($this->disciplines === null) {
            $this->disciplines = [];
            foreach ($this->academic->disciplines() as $discipline) {
                $this->disciplines[$discipline['id']] = $discipline['name'];
            }
        }

        return $this->disciplines;
    }

    /**
     * @return array<int, string>
     */
    private function examTypes(): array
    {
        if ($this->examTypes === null) {
            $this->examTypes = [];
            foreach ($this->academic->examTypes() as $examType) {
                $this->examTypes[$examType['id']] = $examType['name'];
            }
        }

        return $this->examTypes;
    }

    /**
     * @return array<int, string>
     */
    private function authors(): array
    {
        return $this->authors ??= $this->names('users');
    }

    /**
     * @return array<int, string>
     */
    private function names(string $table): array
    {
        $names = [];
        foreach (DB::table($table)->select(['id', 'name'])->get() as $row) {
            $names[(int) $row->id] = (string) $row->name;
        }

        return $names;
    }
}
