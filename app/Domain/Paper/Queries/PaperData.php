<?php

namespace App\Domain\Paper\Queries;

use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\Models\Examination;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\Paper\CandidatePool;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperSlot;
use App\Domain\Paper\PaperChecks;
use App\Domain\Paper\PaperSlots;
use App\Domain\QuestionBank\Models\CognitiveLevel;
use App\Domain\QuestionBank\Models\DifficultyLevel;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The paper as its screen shows it: row by row against the blueprint, each question with what the
 * setter needs to judge it, the mix reached against the mix asked for, and everything worth a second
 * look. The text of the questions is given only to people whose work needs it.
 */
final class PaperData
{
    public function __construct(
        private readonly PaperSlots $slots,
        private readonly CandidatePool $pool,
        private readonly PaperChecks $checks,
        private readonly CmsAcademic $academic,
        private readonly AccessControl $access,
    ) {}

    /**
     * How far the paper has got, for the examination's own page.
     *
     * @return array{exists: bool, chosen: int, planned: int, marks: float, plannedMarks: float}
     */
    public function summary(Examination $examination): array
    {
        $paper = Paper::query()->where('examination_id', $examination->id)->orderByDesc('version_no')->first();
        $slots = $this->slots->of($examination);

        $chosen = $paper === null ? 0 : DB::table('exm_paper_items')->where('paper_id', $paper->id)->count();
        $marks = $paper === null ? 0.0 : (float) DB::table('exm_paper_items')->where('paper_id', $paper->id)->sum('marks');

        return [
            'exists' => $paper !== null,
            'chosen' => $chosen,
            'planned' => array_sum(array_map(fn (PaperSlot $slot): int => $slot->count, $slots)),
            'marks' => round($marks, 2),
            'plannedMarks' => round(array_sum(array_map(fn (PaperSlot $slot): float => $slot->count * $slot->marks, $slots)), 2),
        ];
    }

    /** Whether the person may read the questions of a paper (not just count them). */
    public function mayRead(User $user, Examination $examination): bool
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);

        foreach (['exam.select_questions', 'exam.approve', 'exam.finalise'] as $permission) {
            if ($this->access->allows($user, $permission, $target)) {
                return true;
            }
        }

        return false;
    }

    /** Whether the person may change the paper as it stands. */
    public function mayEdit(User $user, Examination $examination, ?Paper $paper): bool
    {
        $target = new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id);

        return $paper !== null
            && $paper->status->isEditable()
            && $this->blueprintStatus($examination) === BlueprintStatus::Approved
            && $this->access->allows($user, 'exam.select_questions', $target);
    }

    public function blueprintStatus(Examination $examination): ?BlueprintStatus
    {
        $status = DB::table('exm_blueprints')->where('examination_id', $examination->id)->value('status');

        return $status === null ? null : BlueprintStatus::tryFrom((string) $status);
    }

    /**
     * @return array<string, mixed>
     */
    public function screen(User $user, Examination $examination, ?Paper $paper): array
    {
        $mayRead = $this->mayRead($user, $examination);
        $slots = $this->slots->of($examination);
        $blueprintHash = DB::table('exm_blueprints')->where('examination_id', $examination->id)->value('approved_hash');

        $items = $paper === null ? [] : $this->items($paper, $user, $mayRead);
        $bySlot = [];
        foreach ($items as $item) {
            $bySlot[$item['slotKey']][] = $item;
        }

        $inPaper = array_column($items, 'questionId');
        $topics = $this->topicLabels($examination->course_id);
        $types = QuestionType::query()->pluck('name', 'id');
        $known = [];
        $rows = [];
        foreach ($slots as $slot) {
            $key = $slot->key();
            $known[$key] = true;
            $chosen = $bySlot[$key] ?? [];

            $rows[] = [
                'key' => $key,
                'nodeId' => $slot->nodeId,
                'typeId' => $slot->typeId,
                'marks' => $slot->marks,
                'section' => $slot->section,
                'count' => $slot->count,
                'topic' => $topics[$slot->nodeId] ?? 'A topic that has left the curriculum',
                'typeName' => (string) ($types[$slot->typeId] ?? ''),
                'items' => $chosen,
                'missing' => max(0, $slot->count - count($chosen)),
                'over' => max(0, count($chosen) - $slot->count),
                // What the bank could still give this row.
                'available' => $mayRead ? $this->pool->query($examination, $slot->nodeId, $slot->typeId)->when($inPaper !== [], fn ($query) => $query->whereNotIn('q.id', $inPaper))->count() : null,
            ];
        }

        $unassigned = array_values(array_filter($items, fn (array $item): bool => ! isset($known[$item['slotKey']])));

        $marks = round(array_sum(array_column($items, 'marks')), 2);
        $planned = array_sum(array_map(fn (PaperSlot $slot): int => $slot->count, $slots));
        $warnings = $paper === null ? [] : $this->warnings($items, $rows, $unassigned, $mayRead);

        // What the checks needed of an item is not for the screen.
        $forScreen = fn (array $item): array => array_diff_key($item, ['_versionId' => 0, '_text' => 0]);
        $rows = array_map(fn (array $row): array => [...$row, 'items' => array_map($forScreen, $row['items'])], $rows);
        $unassigned = array_map($forScreen, $unassigned);

        return [
            'paper' => $paper === null ? null : [
                'id' => $paper->id,
                'versionNo' => $paper->version_no,
                'status' => $paper->status->value,
                'statusLabel' => $paper->status->label(),
                'shuffleQuestions' => $paper->shuffle_questions,
                'shuffleOptions' => $paper->shuffle_options,
                'blueprintChanged' => $blueprintHash !== null && $paper->blueprint_hash !== $blueprintHash,
            ],
            'rows' => $rows,
            'unassigned' => $unassigned,
            'totals' => [
                'chosen' => count($items),
                'planned' => $planned,
                'marks' => $marks,
                'plannedMarks' => round(array_sum(array_map(fn (PaperSlot $slot): float => $slot->count * $slot->marks, $slots)), 2),
                'totalMarks' => $examination->total_marks,
            ],
            'mix' => $this->mix($examination, $items),
            'warnings' => $warnings,
            'mayRead' => $mayRead,
            'mayEdit' => $this->mayEdit($user, $examination, $paper),
            'mayStart' => $paper === null
                && $this->blueprintStatus($examination) === BlueprintStatus::Approved
                && $this->access->allows($user, 'exam.select_questions', new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id)),
            'blueprintApproved' => $this->blueprintStatus($examination) === BlueprintStatus::Approved,
            'limits' => ['candidates' => (int) config('exam.paper.candidates_limit'), 'recentMonths' => (int) config('exam.paper.recent_use_months')],
        ];
    }

    /**
     * The questions the picker offers for one row, for the words typed, leaving out those already in
     * the paper.
     *
     * @return list<array<string, mixed>>
     */
    public function candidates(User $user, Examination $examination, Paper $paper, PaperSlot $slot, string $words): array
    {
        $inPaper = DB::table('exm_paper_items')->where('paper_id', $paper->id)->pluck('question_id')->all();
        $hashes = DB::table('exm_paper_items as i')->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')->where('i.paper_id', $paper->id)->pluck('v.content_hash')->all();

        $query = $this->pool->search($this->pool->query($examination, $slot->nodeId, $slot->typeId), $words)
            ->when($inPaper !== [], fn ($query) => $query->whereNotIn('q.id', $inPaper))
            // Never used first, then the longest ago: the same preference the automatic draw has.
            ->orderByRaw('q.last_used_at IS NOT NULL, q.last_used_at ASC, q.id ASC') // raw-sql-reviewed: fixed ordering, no input
            ->limit((int) config('exam.paper.candidates_limit'));

        $levels = $this->levelNames();
        $recent = CarbonImmutable::now()->subMonths((int) config('exam.paper.recent_use_months'));

        return array_values($query->get()->map(fn (stdClass $row): array => [
            'questionId' => (int) $row->question_id,
            'reference' => (string) $row->public_ref,
            'summary' => $this->summary_text((string) $row->stem, null, (string) $row->lead_in),
            'topic' => $this->academic->nodeName((int) $row->node_id) ?? '',
            'cognitive' => $levels['cognitive'][(int) $row->cognitive_level_id] ?? null,
            'difficulty' => $levels['difficulty'][(int) $row->difficulty_level_id] ?? null,
            'timesUsed' => (int) $row->times_used,
            'lastUsed' => $row->last_used_at === null ? null : CarbonImmutable::parse((string) $row->last_used_at)->format('j M Y'),
            'usedRecently' => $row->last_used_at !== null && CarbonImmutable::parse((string) $row->last_used_at)->greaterThanOrEqualTo($recent),
            'sameTextInPaper' => in_array((string) $row->content_hash, $hashes, true),
            'mine' => (int) $row->author_id === $user->id,
            'versionNo' => (int) $row->version_no,
        ])->all());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function items(Paper $paper, User $user, bool $mayRead): array
    {
        $rows = DB::table('exm_paper_items as i')
            ->join('qb_questions as q', 'q.id', '=', 'i.question_id')
            ->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')
            ->leftJoin('qb_question_versions as av', 'av.id', '=', 'q.active_version_id')
            ->where('i.paper_id', $paper->id)
            ->orderBy('i.position')->orderBy('i.id')
            ->get([
                'i.id', 'i.question_id', 'i.version_id', 'i.question_type_id', 'i.row_node_id', 'i.section_name', 'i.marks', 'i.is_locked', 'i.source',
                'q.public_ref', 'q.times_used', 'q.last_used_at', 'q.active_version_id', 'q.is_archived', 'av.version_no as active_version_no',
                'v.version_no', 'v.content_hash', 'v.cognitive_level_id', 'v.difficulty_level_id', 'v.author_id', 'v.stem', 'v.vignette', 'v.lead_in',
            ]);

        $levels = $this->levelNames();
        $types = QuestionType::query()->pluck('name', 'id');
        $recent = CarbonImmutable::now()->subMonths((int) config('exam.paper.recent_use_months'));
        $sameText = array_filter(array_count_values($rows->pluck('content_hash')->map(fn (mixed $hash): string => (string) $hash)->all()), fn (int $count): bool => $count > 1);

        $items = [];
        foreach ($rows as $row) {
            $usedRecently = $row->last_used_at !== null && CarbonImmutable::parse((string) $row->last_used_at)->greaterThanOrEqualTo($recent);
            $gone = (bool) $row->is_archived || $row->active_version_id === null;
            $newer = ! $gone && (int) $row->active_version_id !== (int) $row->version_id;

            $items[] = [
                'id' => (int) $row->id,
                'questionId' => (int) $row->question_id,
                'reference' => (string) $row->public_ref,
                'slotKey' => PaperSlot::keyOf((int) $row->row_node_id, (int) $row->question_type_id, (float) $row->marks, $row->section_name === null ? null : (string) $row->section_name),
                'marks' => (float) $row->marks,
                'summary' => $mayRead ? $this->summary_text((string) $row->stem, $row->vignette === null ? null : (string) $row->vignette, (string) $row->lead_in) : null,
                'typeName' => (string) ($types[(int) $row->question_type_id] ?? ''),
                'versionNo' => (int) $row->version_no,
                'cognitive' => $levels['cognitive'][(int) $row->cognitive_level_id] ?? null,
                'cognitiveId' => $row->cognitive_level_id === null ? null : (int) $row->cognitive_level_id,
                'difficulty' => $levels['difficulty'][(int) $row->difficulty_level_id] ?? null,
                'difficultyId' => $row->difficulty_level_id === null ? null : (int) $row->difficulty_level_id,
                'timesUsed' => (int) $row->times_used,
                'lastUsed' => $row->last_used_at === null ? null : CarbonImmutable::parse((string) $row->last_used_at)->format('j M Y'),
                'isLocked' => (bool) $row->is_locked,
                'source' => (string) $row->source,
                'flags' => array_values(array_filter([
                    $usedRecently ? 'recent' : null,
                    (int) $row->author_id === $user->id ? 'own' : null,
                    $newer ? 'newer' : null,
                    $gone ? 'gone' : null,
                    isset($sameText[(string) $row->content_hash]) ? 'same_text' : null,
                ])),
                'latestVersionNo' => $newer ? (int) $row->active_version_no : null,
                // Used by the checks below, never sent to the screen.
                '_versionId' => (int) $row->version_id,
                '_text' => (string) $row->vignette.' '.(string) $row->stem.' '.(string) $row->lead_in,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{cognitive: list<array<string, mixed>>, difficulty: list<array<string, mixed>>}
     */
    private function mix(Examination $examination, array $items): array
    {
        $targets = DB::table('exm_blueprint_targets as t')->join('exm_blueprints as b', 'b.id', '=', 't.blueprint_id')
            ->where('b.examination_id', $examination->id)->get(['t.dimension', 't.level_id', 't.percent']);

        $build = function (string $dimension, string $itemKey, iterable $levels) use ($targets, $items): array {
            $target = [];
            foreach ($targets as $row) {
                if ($row->dimension === $dimension) {
                    $target[(int) $row->level_id] = (float) $row->percent;
                }
            }
            $counts = array_count_values(array_filter(array_column($items, $itemKey), fn (mixed $id): bool => $id !== null));
            $total = count($items);

            $rows = [];
            foreach ($levels as $level) {
                $count = (int) ($counts[$level->id] ?? 0);
                $rows[] = [
                    'id' => $level->id,
                    'name' => $level->name,
                    'target' => $target[$level->id] ?? null,
                    'count' => $count,
                    'actual' => $total === 0 ? 0.0 : round($count / $total * 100, 1),
                ];
            }

            return $rows;
        };

        return [
            'cognitive' => $build('cognitive', 'cognitiveId', CognitiveLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])),
            'difficulty' => $build('difficulty', 'difficultyId', DifficultyLevel::query()->where('is_active', true)->orderBy('sort_order')->get(['id', 'name'])),
        ];
    }

    /**
     * Everything worth a second look, in words, most serious first.
     *
     * @param  list<array<string, mixed>>  $items
     * @param  list<array<string, mixed>>  $rows
     * @param  list<array<string, mixed>>  $unassigned
     * @return list<array{kind: string, message: string, references: list<string>}>
     */
    private function warnings(array $items, array $rows, array $unassigned, bool $mayRead): array
    {
        $refs = fn (string $flag): array => array_values(array_map(fn (array $item): string => $item['reference'], array_filter($items, fn (array $item): bool => in_array($flag, $item['flags'], true))));
        $months = (int) config('exam.paper.recent_use_months');
        $found = [];
        $add = function (string $kind, string $message, array $references) use (&$found): void {
            if ($references !== []) {
                $found[] = ['kind' => $kind, 'message' => $message, 'references' => array_values($references)];
            }
        };

        $add('gone', 'No longer in use in the question bank — swap them:', $refs('gone'));
        $add('unassigned', 'Not in the blueprint any more (its row was changed or removed) — take them out or restore the row:', array_map(fn (array $item): string => $item['reference'], $unassigned));
        $add('over', 'A row has more questions than the blueprint asks for — take some out:', array_values(array_map(fn (array $row): string => $row['topic'].' · '.$row['typeName'], array_filter($rows, fn (array $row): bool => $row['over'] > 0))));
        $add('same_text', 'The same question text appears more than once:', $refs('same_text'));

        if ($mayRead) {
            $texts = [];
            $ids = array_column($items, '_versionId');
            $options = $ids === [] ? collect() : DB::table('qb_question_options')->whereIn('version_id', $ids)->get(['version_id', 'body', 'is_correct'])->groupBy('version_id');
            foreach ($items as $item) {
                $set = $options->get($item['_versionId'], collect());
                $texts[$item['id']] = [
                    'text' => $item['_text'].' '.$set->pluck('body')->implode(' '),
                    'answers' => $set->where('is_correct', 1)->pluck('body')->map(fn (mixed $body): string => (string) $body)->values()->all(),
                ];
            }

            $byId = array_column($items, null, 'id');
            $cues = $this->checks->cues($texts);
            $add('cue', 'One question may give away the answer to another:', array_map(fn (array $cue): string => $byId[$cue['giver']]['reference'].' gives away '.$byId[$cue['receiver']]['reference'], $cues));
        }

        $add('newer', 'A newer version of the question is in use now — the paper keeps the one that was chosen; swap it to use the newer:', $refs('newer'));
        $add('recent', "Used in an examination within the last {$months} months:", $refs('recent'));
        $add('own', 'You wrote these questions yourself:', $refs('own'));

        return $found;
    }

    /**
     * @return array<int, string> topic id => "Heading → Topic"
     */
    private function topicLabels(int $courseId): array
    {
        $nodes = $this->academic->curriculum($courseId);
        $byId = array_column($nodes, null, 'id');
        $labels = [];
        foreach ($nodes as $node) {
            $names = [$node['name']];
            $parent = $node['parent_id'];
            for ($guard = 0; $parent !== null && isset($byId[$parent]) && $guard < 20; $guard++) {
                array_unshift($names, $byId[$parent]['name']);
                $parent = $byId[$parent]['parent_id'];
            }
            $labels[$node['id']] = implode(' → ', $names);
        }

        return $labels;
    }

    /**
     * @return array{cognitive: array<int, string>, difficulty: array<int, string>}
     */
    private function levelNames(): array
    {
        return [
            'cognitive' => CognitiveLevel::query()->pluck('name', 'id')->all(),
            'difficulty' => DifficultyLevel::query()->pluck('name', 'id')->all(),
        ];
    }

    /** A line of a question for a list: its scenario or stem and its lead-in, without markup. */
    private function summary_text(string $stem, ?string $vignette, ?string $leadIn): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(($vignette !== null && $vignette !== '' ? $vignette.' ' : '').$stem.' '.($leadIn ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')));

        return mb_strlen($text) > 220 ? mb_substr($text, 0, 217).'…' : $text;
    }
}
