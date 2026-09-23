<?php

namespace App\Domain\Analytics\Queries;

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

        return array_values($paperItems->map(function (PaperItem $paperItem) use ($types, $candidateItems, $marks, $totals, $answers, $minCandidates, $requireDoubleMarking): array {
            $type = $types->get($paperItem->question_type_id);
            $items = $candidateItems->get($paperItem->id, collect());

            /** @var Collection<int, array{fraction: float, total: float}> $scored */
            $scored = $items->map(function (CandidatePaperItem $item) use ($marks, $totals, $requireDoubleMarking): ?array {
                $itemMarks = $marks->get($item->id, collect());
                $final = FinalMark::of($itemMarks, $requireDoubleMarking);
                if ($final === null) {
                    return null;
                }

                return [
                    'fraction' => $final->max_marks > 0 ? $final->marks_awarded / $final->max_marks : 0.0,
                    'total' => (float) ($totals[$item->candidate_exam_id] ?? 0.0),
                ];
            })->filter()->values();

            $candidates = $scored->count();
            $observedP = $candidates > 0 ? $scored->avg('fraction') : null;
            $correctCount = $scored->filter(fn (array $s): bool => $s['fraction'] >= 1.0)->count();

            $discrimination = null;
            if ($candidates >= $minCandidates) {
                $ordered = $scored->sortByDesc('total')->values();
                $groupSize = max(1, (int) round($candidates * 0.27));
                $top = $ordered->take($groupSize);
                $bottom = $ordered->slice(-$groupSize);
                $discrimination = $top->avg('fraction') - $bottom->avg('fraction');
            }

            $distractors = null;
            if ($type?->has_options && ! $type->has_items) {
                $options = QuestionOption::query()->where('version_id', $paperItem->version_id)->whereNull('item_id')->get(['id', 'label']);
                $counts = array_fill_keys($options->pluck('label')->all(), 0);
                foreach ($items as $item) {
                    $row = $answers->get($item->id);
                    $payload = $row === null ? [] : (json_decode((string) $row->payload, true) ?? []);
                    foreach ((array) ($payload['selected'] ?? []) as $optionId) {
                        $label = $options->firstWhere('id', (int) $optionId)?->label;
                        if ($label !== null) {
                            $counts[$label] = ($counts[$label] ?? 0) + 1;
                        }
                    }
                }
                $distractors = $candidates > 0
                    ? array_map(fn (int $c): float => round($c / $candidates, 4), $counts)
                    : $counts;
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
                'discrimination' => $discrimination === null ? null : round($discrimination, 4),
                'distractors' => $distractors,
            ];
        })->values()->all());
    }
}
