<?php

namespace App\Domain\Results\Queries;

use App\Domain\Delivery\Enums\AttemptStatus;
use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\QuestionBank\Models\QuestionOption;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\Results\Actions\CompileResult;
use App\Domain\Results\Enums\PublicationStatus;
use App\Domain\Results\Models\ItemRekey;
use App\Domain\Results\Models\ResultPublication;

/**
 * Everything the Results screen shows for one examination: every submitted attempt's compiled
 * result (recompiled on the way in, so the screen is never stale), the approve/publish workflow,
 * and — for whoever may re-key — the paper's own items and whether each has been re-keyed already.
 */
final class ResultsData
{
    public function __construct(private readonly CompileResult $compile) {}

    /**
     * @return array<string, mixed>
     */
    public function forExamination(Examination $examination): array
    {
        $attempts = CandidateExam::query()->where('examination_id', $examination->id)
            ->where('status', AttemptStatus::Submitted)->with('candidate')->orderBy('id')->get();

        $rows = $attempts->map(function (CandidateExam $attempt): array {
            $result = $this->compile->__invoke($attempt);

            return [
                'attemptId' => $attempt->id,
                'candidateNo' => $attempt->candidate->candidate_no,
                'name' => $attempt->candidate->name,
                'rawMarks' => $result->raw_marks,
                'negativeDeduction' => $result->negative_deduction,
                'totalMarks' => $result->total_marks,
                'percentage' => $result->percentage,
                'isPass' => $result->is_pass,
                'pendingItems' => $result->pending_items,
            ];
        })->values()->all();

        $publication = ResultPublication::query()->where('examination_id', $examination->id)->first();

        $paper = Paper::query()->where('examination_id', $examination->id)->latest('version_no')->first();
        $items = $paper === null ? [] : PaperItem::query()->where('paper_id', $paper->id)->orderBy('position')->get();
        $rekeyedItemIds = ItemRekey::query()->whereIn('paper_item_id', collect($items)->pluck('id'))->pluck('paper_item_id')->all();
        $typesById = QuestionType::query()->get()->keyBy('id');

        $itemRows = collect($items)->map(function (PaperItem $item) use ($rekeyedItemIds, $typesById): array {
            $type = $typesById->get($item->question_type_id);

            return [
                'id' => $item->id,
                'position' => $item->position,
                'marks' => (float) $item->marks,
                'isManuallyMarked' => (bool) $type?->is_manually_marked,
                'hasItems' => (bool) $type?->has_items,
                'options' => $type?->has_options
                    ? QuestionOption::query()->where('version_id', $item->version_id)->whereNull('item_id')
                        ->orderBy('sort_order')->get(['id', 'label', 'body'])
                        ->map(fn (QuestionOption $o): array => ['id' => $o->id, 'label' => $o->label, 'body' => $o->body])->values()->all()
                    : [],
                'alreadyRekeyed' => in_array($item->id, $rekeyedItemIds, true),
            ];
        })->values()->all();

        $status = $publication === null ? PublicationStatus::Draft : $publication->status;

        return [
            'attempts' => $rows,
            'items' => $itemRows,
            'publication' => [
                'status' => $status->value,
                'statusLabel' => $status->label(),
                'approvedAt' => $publication?->approved_at?->toIso8601String(),
                'publishedAt' => $publication?->published_at?->toIso8601String(),
            ],
        ];
    }
}
