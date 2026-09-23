<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Delivery\Models\CandidatePaperItem;
use App\Domain\Marking\Models\ItemMark;
use App\Domain\Marking\Models\ItemMarkCriterion;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Support\Facades\DB;

/**
 * Everything needed to mark or review one item: the question as it was asked, the candidate's own
 * answer, and every mark recorded for it so far (blind to the caller — RecordExaminerMark, not this
 * query, decides what an examiner mid-marking may see of their peer's mark).
 */
final class ItemMarkData
{
    /**
     * @return array<string, mixed>
     */
    public function forItem(CandidatePaperItem $item): array
    {
        $paperItem = $item->paperItem;
        $type = QuestionType::query()->findOrFail($paperItem->question_type_id);
        $version = QuestionVersion::query()->with(['options', 'items', 'rubricCriteria'])->findOrFail($paperItem->version_id);

        $answer = DB::table('dlv_answers_current')->where('candidate_exam_id', $item->candidate_exam_id)
            ->where('cand_paper_item_id', $item->id)->first();

        return [
            'item' => [
                'id' => $item->id,
                'position' => $item->position,
                'marks' => (float) $paperItem->marks,
                'typeCode' => $type->code,
                'vignette' => $version->vignette,
                'stem' => $version->stem,
                'leadIn' => $version->lead_in,
                'options' => $version->options->map(fn ($o): array => ['id' => $o->id, 'label' => $o->label, 'body' => $o->body])->values()->all(),
                'items' => $version->items->map(fn ($i): array => ['id' => $i->id, 'body' => $i->body])->values()->all(),
                'rubricCriteria' => $version->rubricCriteria->map(fn ($c): array => ['id' => $c->id, 'criterion' => $c->criterion, 'maxMarks' => $c->max_marks, 'guidance' => $c->guidance])->values()->all(),
            ],
            'answer' => $answer === null ? null : json_decode((string) $answer->payload, true),
            'marks' => ItemMark::query()->where('cand_paper_item_id', $item->id)
                ->where('candidate_exam_id', $item->candidate_exam_id)->with('criteria')->get()
                ->map(fn (ItemMark $m): array => [
                    'source' => $m->source->value,
                    'sourceLabel' => $m->source->label(),
                    'marksAwarded' => $m->marks_awarded,
                    'comments' => $m->comments,
                    'markedAt' => $m->marked_at->toIso8601String(),
                    'criteria' => $m->criteria->map(fn (ItemMarkCriterion $c): array => ['rubricCriterionId' => $c->rubric_criterion_id, 'marksAwarded' => $c->marks_awarded])->values()->all(),
                ])->values()->all(),
        ];
    }
}
