<?php

namespace App\Domain\Analytics\Queries;

use App\Domain\Analytics\Support\Bands;
use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Exam\Models\Examination;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\Marking\Queries\FinalMark;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\Results\Models\Result;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Item analysis (exam phase, step 22): difficulty, discrimination and distractor shares for every
 * item of an examination's paper, from the same final marks results already reuse
 * (App\Domain\Marking\Queries\FinalMark) — analysis never disagrees with results about what a
 * candidate got for an item.
 */
final class ItemAnalysis
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forExamination(Examination $examination): array
    {
        $paper = Paper::query()->where('examination_id', $examination->id)->latest('version_no')->first();
        if ($paper === null) {
            return [];
        }

        $paperItems = PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get();
        $types = QuestionType::query()->get()->keyBy('id');

        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->get();
        $totals = Result::query()->whereIn('candidate_exam_id', $attempts->pluck('id'))->pluck('total_marks', 'candidate_exam_id');

        $candidateItems = CandidatePaperItem::query()->whereIn('candidate_exam_id', $attempts->pluck('id'))
            ->whereIn('paper_item_id', $paperItems->pluck('id'))->get()->groupBy('paper_item_id');

        $marks = ItemMark::query()->whereIn('cand_paper_item_id', $candidateItems->flatten()->pluck('id'))->get()
            ->groupBy('cand_paper_item_id');

        $answers = DB::table('dlv_answers_current')->whereIn('cand_paper_item_id', $candidateItems->flatten()->pluck('id'))
            ->get(['cand_paper_item_id', 'payload'])->keyBy('cand_paper_item_id');

        $minCandidates = (int) config('exam.analytics.min_candidates');
        $requireDoubleMarking = $examination->require_double_marking;

        $functionalShare = (float) config('exam.analytics.functional_distractor_share');

        return array_values($paperItems->map(function (PaperItem $paperItem) use ($types, $candidateItems, $marks, $totals, $answers, $minCandidates, $requireDoubleMarking, $functionalShare): array {
            $type = $types->get($paperItem->question_type_id);
            $items = $candidateItems->get($paperItem->id, collect());

            /** @var Collection<int, array{id: int, fraction: float, total: float}> $scored */
            $scored = $items->map(function (CandidatePaperItem $item) use ($marks, $totals, $requireDoubleMarking): ?array {
                $itemMarks = $marks->get($item->id, collect());
                $final = FinalMark::of($itemMarks, $requireDoubleMarking);
                if ($final === null) {
                    return null;
                }

                return [
                    'id' => $item->id,
                    'fraction' => $final->max_marks > 0 ? $final->marks_awarded / $final->max_marks : 0.0,
                    'total' => (float) ($totals[$item->candidate_exam_id] ?? 0.0),
                ];
            })->filter()->values();

            $candidates = $scored->count();
            $observedP = $candidates > 0 ? $scored->avg('fraction') : null;
            $correctCount = $scored->filter(fn (array $s): bool => $s['fraction'] >= 1.0)->count();

            // The upper and lower 27% of candidates by their total for the paper.
            $discrimination = null;
            $upperIds = [];
            $lowerIds = [];
            if ($candidates >= $minCandidates) {
                $ordered = $scored->sortByDesc('total')->values();
                $groupSize = max(1, (int) round($candidates * 0.27));
                $top = $ordered->take($groupSize);
                $bottom = $ordered->slice(-$groupSize);
                $discrimination = $top->avg('fraction') - $bottom->avg('fraction');
                $upperIds = $top->pluck('id')->all();
                $lowerIds = $bottom->pluck('id')->all();
            }

            $distractors = null;
            $optionRows = null;
            $distractorAnalysis = null;
            if ($type?->has_options && ! $type->has_items) {
                $options = QuestionOption::query()->where('version_id', $paperItem->version_id)->whereNull('item_id')
                    ->orderBy('sort_order')->get(['id', 'label', 'is_correct']);
                $counts = array_fill_keys($options->pluck('label')->all(), 0);
                $upper = $counts;
                $lower = $counts;
                foreach ($items as $item) {
                    $row = $answers->get($item->id);
                    $payload = $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);
                    foreach ((array) ($payload['selected'] ?? []) as $optionId) {
                        $label = $options->firstWhere('id', (int) $optionId)?->label;
                        if ($label === null) {
                            continue;
                        }
                        $counts[$label] = ($counts[$label] ?? 0) + 1;
                        if (in_array($item->id, $upperIds, true)) {
                            $upper[$label]++;
                        }
                        if (in_array($item->id, $lowerIds, true)) {
                            $lower[$label]++;
                        }
                    }
                }
                $distractors = $candidates > 0
                    ? array_map(fn (int $c): float => round($c / $candidates, 4), $counts)
                    : $counts;

                $share = fn (int $count, int $of): ?float => $of > 0 ? round($count / $of, 4) : null;
                $optionRows = $options->map(fn (QuestionOption $option): array => [
                    'label' => $option->label,
                    'correct' => (bool) $option->is_correct,
                    'share' => $share($counts[$option->label] ?? 0, $candidates),
                    'upper' => $upperIds === [] ? null : $share($upper[$option->label] ?? 0, count($upperIds)),
                    'lower' => $lowerIds === [] ? null : $share($lower[$option->label] ?? 0, count($lowerIds)),
                ])->values()->all();

                $distractorAnalysis = $candidates > 0 ? $this->distractorAnalysis($optionRows, $functionalShare) : null;
            }

            return [
                'paperItemId' => $paperItem->id,
                'versionId' => $paperItem->version_id,
                'questionId' => DB::table('qb_question_versions')->where('id', $paperItem->version_id)->value('question_id'),
                'position' => $paperItem->position,
                'marks' => (float) $paperItem->marks,
                'candidates' => $candidates,
                'correctCount' => $correctCount,
                'observedP' => $observedP === null ? null : round($observedP, 4),
                'difficultyBand' => Bands::difficulty($observedP),
                'discrimination' => $discrimination === null ? null : round($discrimination, 4),
                'discriminationBand' => Bands::discrimination($discrimination),
                'distractors' => $distractors,
                'options' => $optionRows,
                'distractorAnalysis' => $distractorAnalysis,
            ];
        })->values()->all());
    }

    /**
     * KMU's distractor analysis. A distractor is a wrong option:
     *  - non-functional when fewer candidates than the threshold (5%) chose it — it is not testing
     *    anything and could be replaced with a better wrong answer;
     *  - efficiency is the share of distractors that do work (3 of 3 = 100%);
     *  - possibly defective when the stronger candidates chose it more than the weaker ones;
     *  - a possible miskey when more candidates chose a wrong option than the key.
     *
     * @param  list<array{label: string, correct: bool, share: ?float, upper: ?float, lower: ?float}>  $options
     * @return array{wrong: int, functional: int, efficiency: ?int, nonFunctional: list<string>, defective: list<string>, possibleMiskey: list<string>}
     */
    private function distractorAnalysis(array $options, float $functionalShare): array
    {
        $wrong = array_values(array_filter($options, fn (array $option): bool => ! $option['correct']));
        $keyShare = max([0.0, ...array_map(fn (array $option): float => (float) $option['share'], array_filter($options, fn (array $option): bool => $option['correct']))]);

        $nonFunctional = [];
        $defective = [];
        $miskey = [];
        foreach ($wrong as $option) {
            if ((float) $option['share'] < $functionalShare) {
                $nonFunctional[] = $option['label'];
            }
            if ($option['upper'] !== null && $option['lower'] !== null && $option['upper'] > $option['lower']) {
                $defective[] = $option['label'];
            }
            if ((float) $option['share'] > $keyShare) {
                $miskey[] = $option['label'];
            }
        }

        $functional = count($wrong) - count($nonFunctional);

        return [
            'wrong' => count($wrong),
            'functional' => $functional,
            'efficiency' => $wrong === [] ? null : (int) round($functional / count($wrong) * 100),
            'nonFunctional' => $nonFunctional,
            'defective' => $defective,
            'possibleMiskey' => $miskey,
        ];
    }
}
