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

/**
 * Cronbach's alpha over the whole paper (exam phase, step 22), and KR-20 beside it as KMU asks for
 * both. KR-20 is Cronbach's alpha for a paper marked purely right or wrong (every mark either zero or
 * full), so when every item is like that the two are the same figure; when a part mark was given,
 * KR-20 does not apply and only alpha is reported. Unavailable below the same candidate-count
 * threshold item analysis itself uses; a coefficient from a handful of candidates is not a coefficient.
 */
final class Reliability
{
    /**
     * @return array{coefficient: ?float, label: string, candidates: int, alpha: ?float, kr20: ?float, dichotomous: bool, band: array{key: string, label: string}|null}
     */
    public function forExamination(Examination $examination): array
    {
        $result = $this->compute($examination);
        $alpha = $result['coefficient'];

        return [
            ...$result,
            'alpha' => $alpha,
            'kr20' => $result['dichotomous'] ? $alpha : null,
            'band' => Bands::reliability($alpha),
        ];
    }

    /**
     * @return array{coefficient: ?float, label: string, candidates: int, dichotomous: bool}
     */
    private function compute(Examination $examination): array
    {
        $paper = Paper::query()->where('examination_id', $examination->id)->latest('version_no')->first();
        if ($paper === null) {
            return ['coefficient' => null, 'label' => 'Cronbach\'s alpha', 'candidates' => 0, 'dichotomous' => false];
        }

        $paperItems = PaperItem::query()->where('paper_id', $paper->id)->get();
        $attempts = CandidateExam::query()->where('examination_id', $examination->id)->where('status', AttemptStatus::Submitted)->get();

        $candidateItems = CandidatePaperItem::query()->whereIn('candidate_exam_id', $attempts->pluck('id'))
            ->whereIn('paper_item_id', $paperItems->pluck('id'))->get();
        $marks = ItemMark::query()->whereIn('cand_paper_item_id', $candidateItems->pluck('id'))->get()->groupBy('cand_paper_item_id');

        // One row per attempt, one column per paper item — only attempts where every item has a
        // final mark contribute; a partially-marked attempt would understate its own total.
        $matrix = [];
        $dichotomous = true;
        foreach ($attempts as $attempt) {
            $row = [];
            foreach ($candidateItems->where('candidate_exam_id', $attempt->id) as $item) {
                $final = FinalMark::of($marks->get($item->id, collect()), $examination->require_double_marking);
                if ($final === null) {
                    $row = null;

                    break;
                }
                $row[$item->paper_item_id] = $final->marks_awarded;
                if ($final->marks_awarded !== 0.0 && $final->marks_awarded !== $final->max_marks) {
                    $dichotomous = false;
                }
            }
            if ($row !== null && count($row) === $paperItems->count()) {
                $matrix[] = $row;
            }
        }

        $candidates = count($matrix);
        $label = $dichotomous ? 'KR-20' : 'Cronbach\'s alpha';
        $minCandidates = (int) config('exam.analytics.min_candidates');

        if ($candidates < $minCandidates || $paperItems->count() < 2) {
            return ['coefficient' => null, 'label' => $label, 'candidates' => $candidates, 'dichotomous' => $dichotomous];
        }

        $itemIds = $paperItems->pluck('id')->all();
        $itemVarianceSum = 0.0;
        foreach ($itemIds as $itemId) {
            $column = array_map(fn (array $row): float => $row[$itemId], $matrix);
            $itemVarianceSum += $this->variance($column);
        }

        $totals = array_map(fn (array $row): float => array_sum($row), $matrix);
        $totalVariance = $this->variance($totals);

        if ($totalVariance <= 0.0) {
            return ['coefficient' => null, 'label' => $label, 'candidates' => $candidates, 'dichotomous' => $dichotomous];
        }

        $k = count($itemIds);
        $coefficient = ($k / ($k - 1)) * (1 - $itemVarianceSum / $totalVariance);

        return ['coefficient' => round($coefficient, 4), 'label' => $label, 'candidates' => $candidates, 'dichotomous' => $dichotomous];
    }

    /**
     * @param  list<float>  $values
     */
    private function variance(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $mean = array_sum($values) / $n;

        return array_sum(array_map(fn (float $v): float => ($v - $mean) ** 2, $values)) / $n;
    }
}
