<?php

namespace App\Domain\Marking\Actions;

use App\Domain\Delivery\Models\CandidateExam;
use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Support\ObjectiveItemScorer;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use Illuminate\Support\Facades\DB;

/**
 * Marking every objective item of a just-submitted attempt against the sealed key (exam phase,
 * step 20): the answer key is read here, server-side, exactly as ADR-0003 always said it would be
 * — never sent to the candidate's browser. Manually-marked items (essays) are left for an examiner.
 * Called from SubmitAttempt, inside the same transaction as the submission itself.
 */
final class AutoMarkAttempt
{
    public function __construct(private readonly ObjectiveItemScorer $scorer) {}

    public function __invoke(CandidateExam $attempt): void
    {
        $items = $attempt->items()->with('paperItem')->get();
        $typeIds = $items->pluck('paperItem.question_type_id')->unique()->values();
        $types = QuestionType::query()->whereIn('id', $typeIds)->get()->keyBy('id');

        $versionIds = $items->pluck('paperItem.version_id')->unique()->values();
        $versions = QuestionVersion::query()->whereIn('id', $versionIds)
            ->with(['options', 'items.answers', 'answers'])
            ->get()->keyBy('id');

        $answers = DB::table('dlv_answers_current')->where('candidate_exam_id', $attempt->id)
            ->get(['cand_paper_item_id', 'payload'])->keyBy('cand_paper_item_id');

        $rows = [];
        foreach ($items as $item) {
            $paperItem = $item->paperItem;
            $type = $types->get($paperItem->question_type_id);
            if ($type === null || $type->is_manually_marked) {
                continue;
            }

            $version = $versions->get($paperItem->version_id);
            $payload = ($row = $answers->get($item->id)) === null ? [] : (json_decode((string) $row->payload, true) ?? []);
            $marks = $version === null ? 0.0 : $this->scorer->score($type, $version, $payload, (float) $paperItem->marks);

            $rows[] = [
                'candidate_exam_id' => $attempt->id,
                'cand_paper_item_id' => $item->id,
                'source' => MarkSource::Auto->value,
                'marks_awarded' => round($marks, 2),
                'max_marks' => $paperItem->marks,
                'marked_by' => null,
                'comments' => null,
                'marked_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        if ($rows !== []) {
            DB::table('mrk_item_marks')->insertOrIgnore($rows);
        }
    }
}
