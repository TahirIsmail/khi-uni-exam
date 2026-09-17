<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Support\Cms\CmsAcademic;
use App\Support\Html\QuestionHtml;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The life of one question (blueprint 12): its versions, what happened to each of them and when,
 * and any other question in the campus whose text matches (a possible duplicate).
 */
final class QuestionHistory
{
    public function __construct(private readonly CmsAcademic $academic) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Question $question): array
    {
        $versions = $question->versions()->with('type:id,name')->orderByDesc('version_no')->get();
        $authors = DB::table('users')->whereIn('id', $versions->pluck('author_id')->merge($versions->pluck('approved_by'))->filter()->unique()->all() ?: [0])
            ->pluck('name', 'id');

        return [
            'question' => [
                'id' => $question->id,
                'reference' => $question->public_ref,
                'course' => $this->academic->courseLabel($question->course_id) ?? ('#'.$question->course_id),
                'activeVersionId' => $question->active_version_id,
                'latestVersionNo' => $question->latest_version_no,
                'isArchived' => $question->is_archived,
                'archiveReason' => $question->archive_reason,
                'timesUsed' => $question->times_used,
            ],
            'versions' => $versions->map(fn (QuestionVersion $version): array => [
                'id' => $version->id,
                'versionNo' => $version->version_no,
                'status' => $version->status->value,
                'statusLabel' => $version->status->label(),
                'type' => $version->type->name,
                'marks' => $version->marks,
                'author' => $authors[$version->author_id] ?? 'Unknown',
                'isActive' => $question->active_version_id === $version->id,
                'summary' => mb_substr(QuestionHtml::toText($version->stem), 0, 140),
                'createdAt' => $version->created_at?->toIso8601String(),
                'submittedAt' => $version->submitted_at?->toIso8601String(),
                'approvedAt' => $version->approved_at?->toIso8601String(),
                'activatedAt' => $version->activated_at?->toIso8601String(),
            ])->values()->all(),
            'timeline' => $this->timeline($question, $authors->all()),
            'duplicates' => $this->duplicatesOf($question),
        ];
    }

    /**
     * Every step of every version, newest first: written, sent for review, changes asked for,
     * approved, put in use, superseded, retired.
     *
     * @param  array<int|string, mixed>  $authors
     * @return list<array<string, mixed>>
     */
    private function timeline(Question $question, array $authors): array
    {
        $entries = [];

        foreach ($question->versions as $version) {
            $entries[] = [
                'at' => $version->created_at?->toIso8601String(),
                'versionNo' => $version->version_no,
                'what' => $version->version_no === 1 ? 'Question written' : 'Version '.$version->version_no.' started',
                'by' => (string) ($authors[$version->author_id] ?? 'Unknown'),
                'note' => null,
                'status' => VersionStatus::Draft->value,
                'order' => 0,
            ];
        }

        $log = VersionStatusLog::query()
            ->whereIn('version_id', $question->versions->pluck('id')->all() ?: [0])
            ->orderByDesc('id')->get();
        $versionNumbers = $question->versions->pluck('version_no', 'id');
        $actors = DB::table('users')->whereIn('id', $log->pluck('actor_id')->filter()->unique()->all() ?: [0])->pluck('name', 'id');

        foreach ($log as $entry) {
            $entries[] = [
                'at' => $entry->occurred_at->toIso8601String(),
                'versionNo' => (int) ($versionNumbers[$entry->version_id] ?? 0),
                'what' => $this->describe($entry->from_status, $entry->to_status),
                'by' => (string) ($actors[$entry->actor_id] ?? 'System'),
                'note' => $entry->reason,
                'status' => $entry->to_status->value,
                // Steps that happened in the same second still read in the order they happened.
                'order' => $entry->id,
            ];
        }

        usort($entries, fn (array $a, array $b): int => [$b['at'], $b['order'], $b['versionNo']] <=> [$a['at'], $a['order'], $a['versionNo']]);

        return array_map(function (array $entry): array {
            unset($entry['order']);

            return $entry;
        }, $entries);
    }

    private function describe(?VersionStatus $from, VersionStatus $to): string
    {
        return match ($to) {
            VersionStatus::Submitted => $from === VersionStatus::ChangesRequested ? 'Sent for review again' : 'Sent for review',
            VersionStatus::UnderReview => 'Given to a reviewer',
            VersionStatus::ChangesRequested => 'Changes asked for',
            VersionStatus::Approved => 'Approved',
            VersionStatus::Active => 'Put in use',
            VersionStatus::OnHold => 'Put on hold',
            VersionStatus::Superseded => 'Replaced by a newer version',
            VersionStatus::Retired => 'Retired',
            VersionStatus::Archived => 'Archived',
            VersionStatus::Draft => 'Back to draft',
        };
    }

    /**
     * Other questions in the campus whose text matches this one.
     *
     * @return list<array{id: int, reference: string, status: string, summary: string}>
     */
    private function duplicatesOf(Question $question): array
    {
        $hashes = $question->versions->pluck('content_hash')->unique()->all();
        if ($hashes === []) {
            return [];
        }

        $rows = DB::table('qb_question_versions as v')
            ->join('qb_questions as q', 'q.id', '=', 'v.question_id')
            ->where('q.branch_id', $question->branch_id)
            ->where('q.id', '!=', $question->id)
            ->whereIn('v.content_hash', $hashes)
            ->orderBy('q.public_ref')
            ->distinct()
            ->get(['q.id', 'q.public_ref', 'v.status', 'v.stem']);

        return array_values($rows->map(fn (stdClass $row): array => [
            'id' => (int) $row->id,
            'reference' => (string) $row->public_ref,
            'status' => VersionStatus::from((string) $row->status)->label(),
            'summary' => mb_substr(QuestionHtml::toText((string) $row->stem), 0, 120),
        ])->all());
    }
}
