<?php

namespace App\Domain\Delivery\Queries;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
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
        $types = QuestionType::query()->get()->keyBy('id');

        $candidateItems = $attempt->items()->with('paperItem')->get();
        $versionIds = $candidateItems->pluck('paperItem.version_id')->unique()->values();

        /** @var Collection<int, stdClass> $versions */
        $versions = DB::table('qb_question_versions')->whereIn('id', $versionIds)
            ->get(['id', 'vignette', 'stem', 'lead_in'])->keyBy('id');

        $optionsByVersion = DB::table('qb_question_options')->whereIn('version_id', $versionIds)->whereNull('item_id')
            ->orderBy('sort_order')->get(['id', 'version_id', 'label', 'body'])->groupBy('version_id');

        $itemsByVersion = DB::table('qb_question_items')->whereIn('version_id', $versionIds)
            ->orderBy('sort_order')->get(['id', 'version_id', 'body'])->groupBy('version_id');

        /** @var Collection<int, stdClass> $answers */
        $answers = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)
            ->get(['cand_paper_item_id', 'payload', 'flagged'])->keyBy('cand_paper_item_id');

        $rows = array_values($candidateItems->map(function (CandidatePaperItem $candidateItem) use ($types, $versions, $optionsByVersion, $itemsByVersion, $answers): array {
            $paperItem = $candidateItem->paperItem;
            $type = $types->get($paperItem->question_type_id);
            $version = $versions->get($paperItem->version_id);
            $answer = $answers->get($candidateItem->id);

            /** @var Collection<int, stdClass> $versionOptions */
            $versionOptions = $optionsByVersion->get($paperItem->version_id, collect());
            $orderedOptions = $this->inOrder($versionOptions, $candidateItem->option_order);

            /** @var Collection<int, stdClass> $versionItems */
            $versionItems = $itemsByVersion->get($paperItem->version_id, collect());

            return [
                'id' => $candidateItem->id,
                'position' => $candidateItem->position,
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
