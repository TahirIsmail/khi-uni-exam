<?php

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\QuestionType;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What the candidate's browser is given to sit the exam with — never the answer key (ADR-0003:
 * "the answer key never reaches the browser"). Every column selected here is content a candidate is
 * meant to see; marking (step 20) reads the key server-side, from the paper's own items.
 */
final class AttemptData
{
    /**
     * @return array<string, mixed>
     */
    public function screen(CandidateExam $attempt): array
    {
        /** @var Collection<int, stdClass> $answers */
        $answers = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)
            ->get(['cand_paper_item_id', 'payload', 'flagged'])->keyBy('cand_paper_item_id');

        $rows = $this->rows($attempt->items()->with('paperItem')->get()->map(fn (CandidatePaperItem $candidateItem): array => [
            'id' => $candidateItem->id,
            'position' => $candidateItem->position,
            'paperItem' => $candidateItem->paperItem,
            'optionOrder' => $candidateItem->option_order,
            'answer' => $answers->get($candidateItem->id),
        ])->values()->all());

        return [
            'attempt' => [
                'status' => $attempt->status->value,
                'startedAt' => $attempt->started_at?->toIso8601String(),
                'deadlineAt' => $attempt->deadline_at?->toIso8601String(),
                'remainingSeconds' => $this->remainingSeconds($attempt),
                'lastItemId' => $attempt->last_item_id,
            ],
            'items' => $rows,
        ];
    }

    /**
     * The paper as a candidate would be given it, for staff to look at before anybody sits it: in the
     * paper's own order, unshuffled, nothing answered, the whole time on the clock. No attempt is
     * made and nothing is stored.
     *
     * @return array<string, mixed>
     */
    public function preview(Paper $paper, int $durationMinutes): array
    {
        $rows = $this->rows(PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get()
            ->map(fn (PaperItem $item): array => [
                'id' => $item->id,
                'position' => $item->position,
                'paperItem' => $item,
                'optionOrder' => null,
                'answer' => null,
            ])->values()->all());

        return [
            'attempt' => [
                'status' => 'in_progress',
                'startedAt' => null,
                'deadlineAt' => null,
                'remainingSeconds' => $durationMinutes * 60,
                'lastItemId' => null,
            ],
            'items' => $rows,
        ];
    }

    /**
     * @param  array<int, array{id: int, position: int, paperItem: PaperItem|null, optionOrder: list<int>|null, answer: stdClass|null}>  $entries
     * @return list<array<string, mixed>>
     */
    private function rows(array $entries): array
    {
        // An item whose paper item is gone has nothing to show.
        $entries = collect($entries)->filter(fn (array $entry): bool => $entry['paperItem'] !== null);
        $types = QuestionType::query()->get()->keyBy('id');
        $versionIds = $entries->map(fn (array $entry): int => (int) $entry['paperItem']->version_id)->unique()->values();

        /** @var Collection<int, stdClass> $versions */
        $versions = DB::table('qb_question_versions')->whereIn('id', $versionIds)
            ->get(['id', 'vignette', 'stem', 'lead_in'])->keyBy('id');

        $optionsByVersion = DB::table('qb_question_options')->whereIn('version_id', $versionIds)->whereNull('item_id')
            ->orderBy('sort_order')->get(['id', 'version_id', 'label', 'body'])->groupBy('version_id');

        $itemsByVersion = DB::table('qb_question_items')->whereIn('version_id', $versionIds)
            ->orderBy('sort_order')->get(['id', 'version_id', 'body'])->groupBy('version_id');

        return array_values($entries->map(function (array $entry) use ($types, $versions, $optionsByVersion, $itemsByVersion): array {
            $paperItem = $entry['paperItem'];
            $type = $types->get($paperItem->question_type_id);
            $version = $versions->get($paperItem->version_id);
            $answer = $entry['answer'];

            /** @var Collection<int, stdClass> $versionOptions */
            $versionOptions = $optionsByVersion->get($paperItem->version_id, collect());
            $orderedOptions = $this->inOrder($versionOptions, $entry['optionOrder']);

            /** @var Collection<int, stdClass> $versionItems */
            $versionItems = $itemsByVersion->get($paperItem->version_id, collect());

            return [
                'id' => $entry['id'],
                'position' => $entry['position'],
                'marks' => (float) $paperItem->marks,
                'typeCode' => $type?->code,
                'hasOptions' => (bool) $type?->has_options,
                'hasItems' => (bool) $type?->has_items,
                'itemAnswer' => $type === null ? 'none' : $type->item_answer->value,
                'hasAcceptedAnswers' => (bool) $type?->has_accepted_answers,
                'isManuallyMarked' => (bool) $type?->is_manually_marked,
                'correctMax' => $type?->correct_max,
                'vignette' => $version === null ? null : $version->vignette,
                'stem' => $version === null ? '' : $version->stem,
                'leadIn' => $version === null ? null : $version->lead_in,
                'options' => $orderedOptions->map(fn (stdClass $o): array => ['id' => (int) $o->id, 'label' => $o->label, 'body' => $o->body])->values()->all(),
                'items' => $versionItems->map(fn (stdClass $i): array => ['id' => (int) $i->id, 'body' => $i->body])->values()->all(),
                'answer' => $answer === null ? null : json_decode((string) $answer->payload, true),
                'flagged' => $answer !== null && (bool) $answer->flagged,
            ];
        })->all());
    }

    public function remainingSeconds(CandidateExam $attempt): ?int
    {
        if ($attempt->deadline_at === null) {
            return null;
        }

        return max(0, (int) now()->diffInSeconds($attempt->deadline_at, false));
    }

    /**
     * @param  Collection<int, stdClass>  $options
     * @param  list<int>|null  $order
     * @return Collection<int, stdClass>
     */
    private function inOrder(Collection $options, ?array $order): Collection
    {
        if ($order === null) {
            return $options;
        }

        $byId = $options->keyBy('id');

        return collect($order)->map(fn (int $id) => $byId->get($id))->filter();
    }
}
