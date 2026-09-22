<?php

namespace App\Domain\Paper;

use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\Models\PaperSlot;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use stdClass;

/**
 * The parts of choosing a question that adding, swapping and drawing all share: finding the
 * blueprint row an item is for, checking that the question can serve it, putting it in, and keeping
 * the paper in the blueprint's order.
 */
final class PaperItems
{
    public function __construct(
        private readonly PaperSlots $slots,
        private readonly CandidatePool $pool,
    ) {}

    /** The blueprint row with this key, or a refusal that says so. */
    public function slot(Examination $examination, int $nodeId, int $typeId, float $marks, ?string $section): PaperSlot
    {
        $key = PaperSlot::keyOf($nodeId, $typeId, $marks, $section);
        foreach ($this->slots->of($examination) as $slot) {
            if ($slot->key() === $key) {
                return $slot;
            }
        }

        throw ValidationException::withMessages(['slot' => 'That row is not in the blueprint (it may have been changed). Reload the paper.']);
    }

    /**
     * The question as the row's pool holds it, or a refusal: it has to be in use, of this course and
     * examination, of the row's type and from its topic.
     */
    public function candidate(Examination $examination, PaperSlot $slot, int $questionId): stdClass
    {
        $candidate = $this->pool->query($examination, $slot->nodeId, $slot->typeId)->where('q.id', $questionId)->first();

        if ($candidate === null) {
            throw ValidationException::withMessages(['question_id' => 'That question cannot go in this row: it has to be in use in the question bank, filed under this examination, of this type and from this topic.']);
        }

        return $candidate;
    }

    public function mustNotBeInPaper(Paper $paper, int $questionId): void
    {
        if (PaperItem::query()->where('paper_id', $paper->id)->where('question_id', $questionId)->exists()) {
            throw ValidationException::withMessages(['question_id' => 'That question is already in the paper.']);
        }
    }

    public function insert(Paper $paper, PaperSlot $slot, stdClass $candidate, string $source, int $userId): PaperItem
    {
        return PaperItem::query()->create([
            'paper_id' => $paper->id,
            // Placed properly by renumber(), once every change of the operation is in.
            'position' => 0,
            'question_id' => (int) $candidate->question_id,
            'version_id' => (int) $candidate->version_id,
            'question_type_id' => $slot->typeId,
            'row_node_id' => $slot->nodeId,
            'section_name' => $slot->section,
            'marks' => $slot->marks,
            'is_locked' => false,
            'source' => $source,
            'picked_by' => $userId,
        ]);
    }

    /**
     * Puts the items in the blueprint's order: row by row, and within a row in the order they came.
     * An item whose row has gone from the blueprint goes to the end.
     */
    public function renumber(Examination $examination, Paper $paper): void
    {
        $order = [];
        foreach ($this->slots->of($examination) as $index => $slot) {
            $order[$slot->key()] ??= $index;
        }

        $items = PaperItem::query()->where('paper_id', $paper->id)->get()
            ->sortBy(fn (PaperItem $item): string => sprintf('%05d-%010d-%010d', $order[$item->slotKey()] ?? 99999, $item->position === 0 ? PHP_INT_MAX : $item->position, $item->id))
            ->values();

        foreach ($items as $index => $item) {
            if ($item->position !== $index + 1) {
                DB::table('exm_paper_items')->where('id', $item->id)->update(['position' => $index + 1]);
            }
        }
    }

    /** How many items of this paper are in this row. */
    public function filled(Paper $paper, PaperSlot $slot): int
    {
        return PaperItem::query()->where('paper_id', $paper->id)
            ->where('row_node_id', $slot->nodeId)
            ->where('question_type_id', $slot->typeId)
            ->where('marks', $slot->marks)
            ->where(fn ($inner) => $slot->section === null ? $inner->whereNull('section_name') : $inner->where('section_name', $slot->section))
            ->count();
    }
}
