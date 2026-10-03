<?php

namespace App\Domain\QuestionBank\Queries;

use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\PrehocAssessment;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Domain\QuestionBank\Models\Review;
use App\Domain\QuestionBank\Models\VersionStatusLog;
use App\Domain\QuestionBank\Review\ReviewLevels;
use App\Domain\QuestionBank\Review\ReviewStage;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use App\Support\Cms\CmsSettings;
use App\Support\Html\QuestionHtml;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The life of one question (blueprint 12): its versions, what happened to each of them and when,
 * and any other question in the campus whose text matches (a possible duplicate).
 */
final class QuestionHistory
{
    public function __construct(
        private readonly CmsAcademic $academic,
        private readonly CmsSettings $settings,
        private readonly ReviewLevels $levels,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function for(Question $question, User $viewer): array
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
                'statusLabel' => $version->kmuStatus(),
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
            'timeline' => $this->timeline($question, $authors->all(), $viewer),
            'usage' => $this->usage($question),
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
    private function timeline(Question $question, array $authors, User $viewer): array
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

        foreach ($this->reviewEntries($question, $viewer) as $entry) {
            $entries[] = $entry;
        }

        usort($entries, fn (array $a, array $b): int => [$b['at'], $b['order'], $b['versionNo']] <=> [$a['at'], $a['order'], $a['versionNo']]);

        return array_map(function (array $entry): array {
            unset($entry['order']);

            return $entry;
        }, $entries);
    }

    /**
     * What the reviewers said and what anybody judged about the question: each reviewer's values
     * and the consolidated ones the approver settled on are all shown here, not only the outcome.
     * Reviewer names are hidden from the author when kmu-cms asks for that.
     *
     * @return list<array{at: string|null, versionNo: int, what: string, by: string, note: string|null, status: string, order: int}>
     */
    private function reviewEntries(Question $question, User $viewer): array
    {
        $versionIds = $question->versions->pluck('id')->all() ?: [0];
        $versionNumbers = $question->versions->pluck('version_no', 'id');
        $authorIds = $question->versions->pluck('author_id')->unique()->all();

        $reviews = Review::query()->whereIn('version_id', $versionIds)->with('decision:id,name')->orderBy('id')->get();
        $prehoc = PrehocAssessment::query()->whereIn('version_id', $versionIds)->orderBy('id')->get();

        $people = DB::table('users')
            ->whereIn('id', $reviews->pluck('reviewer_id')->merge($prehoc->pluck('assessed_by'))->unique()->all() ?: [0])
            ->pluck('name', 'id');
        $levels = [
            'cognitive' => DB::table('qb_cognitive_levels')->pluck('name', 'id'),
            'difficulty' => DB::table('qb_difficulty_levels')->pluck('name', 'id'),
        ];

        // The author of any version of this question sees the comments but, when kmu-cms says so,
        // not who wrote them.
        $hideNames = $this->settings->reviewerAnonymous() && in_array($viewer->id, $authorIds, true);
        $name = fn (int $id): string => $hideNames && $id !== $viewer->id ? 'A reviewer' : (string) ($people[$id] ?? 'Unknown');

        $entries = [];
        foreach ($reviews as $review) {
            $entries[] = [
                'at' => $review->submitted_at->toIso8601String(),
                'versionNo' => (int) ($versionNumbers[$review->version_id] ?? 0),
                'what' => $this->levels->label(ReviewStage::from($review->stage)).': '.($review->requestedChanges()
                    ? 'changes asked for'
                    : ($review->decision_id === null ? 'no decision recorded' : $review->decision->name)),
                'by' => $name((int) $review->reviewer_id),
                'note' => $review->comments,
                'status' => $review->requestedChanges() ? VersionStatus::ChangesRequested->value : VersionStatus::UnderReview->value,
                'order' => 1_000_000 + $review->id,
            ];
        }

        foreach ($prehoc as $row) {
            $values = array_filter([
                $row->cognitive_level_id === null ? null : (string) ($levels['cognitive'][$row->cognitive_level_id] ?? ''),
                $row->difficulty_level_id === null ? null : (string) ($levels['difficulty'][$row->difficulty_level_id] ?? ''),
                $row->estimated_p === null ? null : 'expected pass rate '.$row->estimated_p,
            ]);

            $entries[] = [
                'at' => $row->assessed_at->toIso8601String(),
                'versionNo' => (int) ($versionNumbers[$row->version_id] ?? 0),
                'what' => match ($row->source) {
                    'author' => 'Author proposed: '.implode(' · ', $values),
                    'consolidated' => 'Settled on approval: '.implode(' · ', $values),
                    default => 'Reviewer judged: '.implode(' · ', $values),
                },
                'by' => $name((int) $row->assessed_by),
                'note' => $row->reason,
                'status' => $row->is_consolidated ? VersionStatus::Approved->value : VersionStatus::Submitted->value,
                'order' => 2_000_000 + $row->id,
            ];
        }

        return $entries;
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
     * Where the question has been used (KMU QBank "Question history"): how many times, in which
     * examination and when, how many students attempted it, and how it performed there. Filled in
     * by the examination and post-hoc phases; until then a question has no uses.
     *
     * @return array{timesUsed: int, candidatesTotal: int, lastUsedAt: string|null, exams: list<array{exam: string, usedOn: string|null, candidates: int|null, difficultyIndex: float|null, discriminationIndex: float|null}>}
     */
    private function usage(Question $question): array
    {
        $rows = DB::table('qb_question_usage')
            ->where('question_id', $question->id)
            ->orderByDesc('used_on')
            ->orderByDesc('id')
            ->get(['exam_label', 'used_on', 'candidates', 'observed_p', 'discrimination']);

        return [
            'timesUsed' => $question->times_used,
            'candidatesTotal' => $question->candidates_total,
            'lastUsedAt' => $question->last_used_at?->toIso8601String(),
            'exams' => array_values($rows->map(fn (stdClass $row): array => [
                'exam' => (string) ($row->exam_label ?? 'Examination'),
                'usedOn' => $row->used_on === null ? null : (string) $row->used_on,
                'candidates' => $row->candidates === null ? null : (int) $row->candidates,
                'difficultyIndex' => $row->observed_p === null ? null : (float) $row->observed_p,
                'discriminationIndex' => $row->discrimination === null ? null : (float) $row->discrimination,
            ])->all()),
        ];
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
