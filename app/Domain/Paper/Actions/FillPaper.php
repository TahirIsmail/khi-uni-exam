<?php

namespace App\Domain\Paper\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Models\Examination;
use App\Domain\Paper\CandidatePool;
use App\Domain\Paper\Models\Paper;
use App\Domain\Paper\Models\PaperItem;
use App\Domain\Paper\PaperItems;
use App\Domain\Paper\PaperSelector;
use App\Domain\Paper\PaperSlots;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Chooses questions from the question bank for the rows of the blueprint that are not full yet.
 *
 *  - "gaps": everything already in the paper stays, and the missing questions are drawn.
 *  - "redraw": everything that is not locked is taken out first, and the paper is drawn again.
 *
 * What a row can draw on is CandidatePool's word; the mix and the preference for questions not used
 * lately are PaperSelector's. Whatever the bank cannot supply is left as a gap, and said so.
 */
final class FillPaper
{
    public function __construct(
        private readonly PaperGuard $guard,
        private readonly PaperSlots $slots,
        private readonly PaperItems $items,
        private readonly CandidatePool $pool,
        private readonly PaperSelector $selector,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{added: int, removed: int, missing: int}
     */
    public function __invoke(User $user, Examination $examination, Paper $paper, string $mode): array
    {
        $this->guard->authorise($user, $examination);
        $this->guard->mustBeEditable($examination, $paper);

        return DB::transaction(function () use ($user, $examination, $paper, $mode): array {
            Paper::query()->whereKey($paper->id)->lockForUpdate()->firstOrFail();

            $removed = 0;
            if ($mode === 'redraw') {
                $removed = PaperItem::query()->where('paper_id', $paper->id)->where('is_locked', false)->delete();
            }

            $slots = $this->slots->of($examination);
            $planned = array_sum(array_map(fn ($slot): int => $slot->count, $slots));
            $wanted = $this->wantedMix($examination, $planned);

            // What the paper has already: which questions, which texts, and the mix so far.
            $taken = [];
            $hashes = [];
            $chosen = ['cognitive' => [], 'difficulty' => []];
            foreach (DB::table('exm_paper_items as i')->join('qb_question_versions as v', 'v.id', '=', 'i.version_id')
                ->where('i.paper_id', $paper->id)->get(['i.question_id', 'v.content_hash', 'v.cognitive_level_id', 'v.difficulty_level_id']) as $row) {
                $taken[(int) $row->question_id] = true;
                $hashes[(string) $row->content_hash] = true;
                foreach (['cognitive' => 'cognitive_level_id', 'difficulty' => 'difficulty_level_id'] as $dimension => $column) {
                    if ($row->{$column} !== null) {
                        $chosen[$dimension][(int) $row->{$column}] = ($chosen[$dimension][(int) $row->{$column}] ?? 0) + 1;
                    }
                }
            }

            // What each row that is not full can draw on.
            $plan = [];
            foreach ($slots as $slot) {
                $need = $slot->count - $this->items->filled($paper, $slot);
                if ($need <= 0) {
                    continue;
                }

                $plan[] = [
                    'slot' => $slot,
                    'need' => $need,
                    'candidates' => array_values($this->pool->query($examination, $slot->nodeId, $slot->typeId)->limit(2000)->get()
                        ->map(fn ($row): array => [
                            'question_id' => (int) $row->question_id,
                            'content_hash' => (string) $row->content_hash,
                            'cognitive_level_id' => $row->cognitive_level_id === null ? null : (int) $row->cognitive_level_id,
                            'difficulty_level_id' => $row->difficulty_level_id === null ? null : (int) $row->difficulty_level_id,
                            'times_used' => (int) $row->times_used,
                            'last_used_at' => $row->last_used_at === null ? null : (string) $row->last_used_at,
                        ])->all()),
                ];
            }

            // The rows with the least choice are drawn first. A row that can only supply one level of
            // thinking has to be given the chance to, or a row with plenty of choice would use up the
            // questions the mix needs from it.
            usort($plan, fn (array $a, array $b): int => [$this->flexibility($a['candidates']), count($a['candidates']) - $a['need']]
                <=> [$this->flexibility($b['candidates']), count($b['candidates']) - $b['need']]);

            $added = 0;
            $missing = 0;
            $references = [];
            foreach ($plan as ['slot' => $slot, 'need' => $need, 'candidates' => $candidates]) {
                $picked = $this->selector->pick($candidates, $need, $wanted, $chosen, $taken, $hashes);
                foreach ($picked as $questionId) {
                    $candidate = $this->items->candidate($examination, $slot, $questionId);
                    $this->items->insert($paper, $slot, $candidate, 'auto', $user->id);
                    $references[] = (string) $candidate->public_ref;
                    $added++;
                }
                $missing += $need - count($picked);
            }

            $this->items->renumber($examination, $paper);
            $paper->update([
                'blueprint_hash' => Blueprint::query()->where('examination_id', $examination->id)->value('approved_hash'),
                'updated_by' => $user->id,
            ]);

            $this->audit->record('paper.drawn', 'paper', $paper->id, null, [
                'mode' => $mode,
                'added' => $added,
                'removed' => $removed,
                'missing' => $missing,
                'questions' => $references,
            ], null, $user, $examination->branch_id);

            return ['added' => $added, 'removed' => $removed, 'missing' => $missing];
        });
    }

    /**
     * How many different levels of thinking and difficulty a row's questions cover: the fewer, the
     * less choice the row has.
     *
     * @param  list<array{question_id: int, content_hash: string, cognitive_level_id: int|null, difficulty_level_id: int|null, times_used: int, last_used_at: string|null}>  $candidates
     */
    private function flexibility(array $candidates): int
    {
        return count(array_unique(array_filter(array_column($candidates, 'cognitive_level_id'), fn (?int $id): bool => $id !== null)))
            + count(array_unique(array_filter(array_column($candidates, 'difficulty_level_id'), fn (?int $id): bool => $id !== null)));
    }

    /**
     * How many questions each level should have in the whole paper, from the blueprint's mixes.
     *
     * @return array{cognitive: array<int, int>, difficulty: array<int, int>}
     */
    private function wantedMix(Examination $examination, int $planned): array
    {
        $cognitive = [];
        $difficulty = [];
        $targets = DB::table('exm_blueprint_targets as t')
            ->join('exm_blueprints as b', 'b.id', '=', 't.blueprint_id')
            ->where('b.examination_id', $examination->id)
            ->get(['t.dimension', 't.level_id', 't.percent']);

        foreach ($targets as $target) {
            $count = (int) round((float) $target->percent / 100 * $planned);
            if ($target->dimension === 'cognitive') {
                $cognitive[(int) $target->level_id] = $count;
            } else {
                $difficulty[(int) $target->level_id] = $count;
            }
        }

        return ['cognitive' => $cognitive, 'difficulty' => $difficulty];
    }
}
