<?php

namespace App\Domain\Paper;

use Carbon\CarbonImmutable;

/**
 * Chooses questions for a paper from those a row can draw on. It aims for the mix the blueprint asks
 * for, and among questions that serve the mix equally it prefers those never used, then those not
 * used lately, and leaves questions used within the recent-use period for last. A small random
 * element means two draws are not identical, and papers for different sittings differ.
 *
 * It is a heuristic and says so on the screen: the coverage panel shows the mix the draw reached.
 */
final class PaperSelector
{
    /**
     * @param  list<array{question_id: int, content_hash: string, cognitive_level_id: int|null, difficulty_level_id: int|null, times_used: int, last_used_at: string|null}>  $candidates
     * @param  array{cognitive: array<int, int>, difficulty: array<int, int>}  $wanted  how many questions each level should have in the paper
     * @param  array{cognitive: array<int, int>, difficulty: array<int, int>}  $chosen  how many it has so far; updated as questions are picked
     * @param  array<int, true>  $takenIds  questions already in the paper; updated
     * @param  array<string, true>  $takenHashes  texts already in the paper; updated
     * @return list<int> the questions picked, in the order they were
     */
    public function pick(array $candidates, int $count, array $wanted, array &$chosen, array &$takenIds, array &$takenHashes): array
    {
        $recent = CarbonImmutable::now()->subMonths((int) config('exam.paper.recent_use_months'));
        $picked = [];

        for ($n = 0; $n < $count; $n++) {
            $best = null;
            $bestScore = -INF;

            foreach ($candidates as $candidate) {
                if (isset($takenIds[$candidate['question_id']]) || isset($takenHashes[$candidate['content_hash']])) {
                    continue;
                }

                $score = $this->score($candidate, $wanted, $chosen, $recent);
                if ($score > $bestScore) {
                    $best = $candidate;
                    $bestScore = $score;
                }
            }

            if ($best === null) {
                break;
            }

            $picked[] = $best['question_id'];
            $takenIds[$best['question_id']] = true;
            $takenHashes[$best['content_hash']] = true;
            foreach (['cognitive' => 'cognitive_level_id', 'difficulty' => 'difficulty_level_id'] as $dimension => $column) {
                if ($best[$column] !== null) {
                    $chosen[$dimension][$best[$column]] = ($chosen[$dimension][$best[$column]] ?? 0) + 1;
                }
            }
        }

        return $picked;
    }

    /**
     * @param  array{question_id: int, content_hash: string, cognitive_level_id: int|null, difficulty_level_id: int|null, times_used: int, last_used_at: string|null}  $candidate
     * @param  array{cognitive: array<int, int>, difficulty: array<int, int>}  $wanted
     * @param  array{cognitive: array<int, int>, difficulty: array<int, int>}  $chosen
     */
    private function score(array $candidate, array $wanted, array $chosen, CarbonImmutable $recent): float
    {
        $score = 0.0;

        // How far below its share each of the question's levels still is: a question that fills a
        // gap in the mix scores above one that adds to a level that already has enough.
        foreach (['cognitive' => 'cognitive_level_id', 'difficulty' => 'difficulty_level_id'] as $dimension => $column) {
            $level = $candidate[$column];
            if ($level !== null && $wanted[$dimension] !== []) {
                $score += ($wanted[$dimension][$level] ?? 0) - ($chosen[$dimension][$level] ?? 0);
            }
        }

        if ($candidate['times_used'] === 0 || $candidate['last_used_at'] === null) {
            $score += 1.0;
        } elseif (CarbonImmutable::parse($candidate['last_used_at'])->lessThan($recent)) {
            $score += 0.5;
        } else {
            $score -= 3.0;
        }

        return $score + random_int(0, 1000) / 10000;
    }
}
