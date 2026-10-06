<?php

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\Marking\Queries\AttemptScore;
use App\Domain\Marking\Queries\FinalMark;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * One candidate's attempt for staff to go through after the exam: every question in the order the
 * candidate had it, the options in the order they saw them, which one they chose, which one is
 * right, and the mark it got — the answer key included, so this is for staff only.
 */
final class AttemptReview
{
    public function __construct(private readonly AttemptScore $score) {}

    /**
     * @return array{candidate: array<string, mixed>, score: array<string, mixed>, counts: array<string, int>, items: list<array<string, mixed>>}
     */
    public function for(CandidateExam $attempt): array
    {
        $attempt->loadMissing(['candidate', 'items.paperItem', 'examination']);
        $zone = (string) config('exam.timezone');
        $items = $attempt->items->sortBy('position')->values();
        $versionIds = $items->map(fn (CandidatePaperItem $i): int => (int) $i->paperItem->version_id)->unique()->values()->all();

        $versions = DB::table('qb_question_versions')->whereIn('id', $versionIds)->get(['id', 'vignette', 'stem', 'lead_in'])->keyBy('id');
        /** @var Collection<int, Collection<int, stdClass>> $options */
        $options = DB::table('qb_question_options')->whereIn('version_id', $versionIds)->whereNull('item_id')
            ->orderBy('sort_order')->get(['id', 'version_id', 'label', 'body', 'is_correct'])->groupBy('version_id');
        $answers = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)
            ->get(['cand_paper_item_id', 'payload', 'flagged'])->keyBy('cand_paper_item_id');
        $marks = ItemMark::query()->where('candidate_exam_id', $attempt->id)->get()->groupBy('cand_paper_item_id');

        $counts = ['correct' => 0, 'wrong' => 0, 'blank' => 0, 'partial' => 0, 'pending' => 0];
        $rows = $items->map(function (CandidatePaperItem $item) use ($versions, $options, $answers, $marks, $attempt, &$counts): array {
            $paperItem = $item->paperItem;
            $version = $versions->get($paperItem->version_id);
            $answer = $answers->get($item->id);
            $payload = $answer === null ? [] : (array) json_decode((string) $answer->payload, true);
            $chosen = array_map('intval', (array) ($payload['selected'] ?? []));

            $versionOptions = $options->get($paperItem->version_id, collect());
            $order = $item->option_order;
            if (is_array($order) && $order !== []) {
                $byId = $versionOptions->keyBy('id');
                $versionOptions = collect($order)->map(fn (int $id): ?stdClass => $byId->get($id))->filter()->values();
            }

            $final = FinalMark::of($marks->get($item->id, collect()), $attempt->examination->require_double_marking);
            $max = (float) $paperItem->marks;
            $answered = $chosen !== [] || trim((string) ($payload['text'] ?? '')) !== '' || ! empty($payload['items']) || ! empty($payload['order']);
            $outcome = match (true) {
                ! $answered => 'blank',
                $final === null => 'pending',
                (float) $final->marks_awarded >= $max => 'correct',
                (float) $final->marks_awarded > 0 => 'partial',
                default => 'wrong',
            };
            $counts[$outcome]++;

            return [
                'id' => $item->id,
                'position' => $item->position,
                'vignette' => $version?->vignette,
                'stem' => (string) ($version->stem ?? ''),
                'leadIn' => $version?->lead_in,
                'options' => $versionOptions->values()->map(fn (stdClass $o, int $i): array => [
                    'letter' => chr(65 + $i),
                    'body' => (string) $o->body,
                    'isCorrect' => (bool) $o->is_correct,
                    'chosen' => in_array((int) $o->id, $chosen, true),
                ])->all(),
                'answerText' => isset($payload['text']) ? (string) $payload['text'] : null,
                'flagged' => $answer !== null && (bool) $answer->flagged,
                'marks' => $max,
                'awarded' => $final === null ? null : (float) $final->marks_awarded,
                'outcome' => $outcome,
            ];
        })->all();

        return [
            'candidate' => [
                'candidateNo' => (string) $attempt->candidate->candidate_no,
                'name' => (string) $attempt->candidate->name,
                'rollNo' => $attempt->candidate->roll_no,
                'status' => $attempt->status->label(),
                'startedAt' => $attempt->started_at?->setTimezone($zone)->format('j M Y, g:i A'),
                'submittedAt' => $attempt->submitted_at?->setTimezone($zone)->format('j M Y, g:i A'),
            ],
            'score' => $this->score->of($attempt),
            'counts' => $counts,
            'items' => array_values($rows),
        ];
    }
}
