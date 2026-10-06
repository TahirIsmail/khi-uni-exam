<?php

namespace App\Domain\Reports\Queries;

use App\Domain\Results\Models\ComponentMark;
use App\Domain\Results\Models\ResultComponent;
use Illuminate\Support\Collection;

/**
 * The components of a set of examinations, and every candidate's marks for them — read once for a
 * whole sheet rather than per candidate.
 *
 * Everything here is written so that an examination with no components behaves exactly as it did
 * before components existed: the sheet asks for a subject total, gets the paper's own marks back,
 * and nothing about it changes.
 */
final class CourseComponents
{
    /** @var array<int, list<ResultComponent>> keyed by examination */
    private array $components = [];

    /** @var array<int, array<int, float>> marks keyed by component, then candidate */
    private array $marks = [];

    /**
     * @param  list<int>  $examinationIds
     */
    public function load(array $examinationIds): self
    {
        $components = ResultComponent::query()->whereIn('examination_id', $examinationIds)
            ->orderBy('sort_order')->get();

        $this->components = $components->groupBy('examination_id')
            ->map(fn (Collection $group): array => array_values($group->all()))->all();

        $this->marks = ComponentMark::query()->whereIn('component_id', $components->pluck('id'))->get()
            ->groupBy('component_id')
            ->map(fn (Collection $group): array => $group->pluck('marks', 'candidate_id')
                ->map(fn (mixed $m): float => (float) $m)->all())
            ->all();

        return $this;
    }

    /**
     * @return list<ResultComponent>
     */
    public function of(int $examinationId): array
    {
        return $this->components[$examinationId] ?? [];
    }

    public function has(int $examinationId): bool
    {
        return $this->of($examinationId) !== [];
    }

    /**
     * One candidate's result for one course, once every component is counted.
     *
     * `$paperMarks` is what this system's own marking produced, which is null when the paper is
     * still being marked. A component nobody has entered yet is likewise null, and either makes the
     * subject incomplete rather than making it a low mark.
     *
     * @return array{
     *     obtained: float,
     *     possible: float,
     *     percentage: float|null,
     *     complete: bool,
     *     isPass: bool,
     *     failedGroups: list<string>,
     *     parts: list<array{code: string, name: string, marks: float|null, maxMarks: float}>,
     * }
     */
    public function resultFor(int $examinationId, int $candidateId, ?float $paperMarks): array
    {
        $obtained = 0.0;
        $possible = 0.0;
        $complete = true;
        $parts = [];

        /** @var array<string, array{obtained: float, possible: float, bar: float|null}> $groups */
        $groups = [];

        foreach ($this->of($examinationId) as $component) {
            $marks = $component->isPaper()
                ? $paperMarks
                : ($this->marks[$component->id][$candidateId] ?? null);

            $parts[] = [
                'code' => $component->code,
                'name' => $component->name,
                'marks' => $marks,
                'maxMarks' => $component->max_marks,
            ];

            if ($marks === null) {
                $complete = false;

                continue;
            }

            $obtained += $marks;
            $possible += $component->max_marks;

            $groups[$component->group] ??= ['obtained' => 0.0, 'possible' => 0.0, 'bar' => null];
            $groups[$component->group]['obtained'] += $marks;
            $groups[$component->group]['possible'] += $component->max_marks;

            // A group's bar is the highest any of its components asks for: one component demanding
            // 50% makes the whole group a 50% hurdle, which is how the separate pass rule reads.
            if ($component->min_pass_percentage !== null) {
                $groups[$component->group]['bar'] = max(
                    $groups[$component->group]['bar'] ?? 0.0,
                    $component->min_pass_percentage,
                );
            }
        }

        $failedGroups = [];
        if ($complete) {
            foreach ($groups as $name => $group) {
                if ($group['bar'] === null || $group['possible'] <= 0) {
                    continue;
                }
                if (($group['obtained'] / $group['possible']) * 100 < $group['bar']) {
                    $failedGroups[] = $name;
                }
            }
        }

        return [
            'obtained' => round($obtained, 2),
            'possible' => round($possible, 2),
            'percentage' => $possible > 0 ? round(($obtained / $possible) * 100, 2) : null,
            'complete' => $complete,
            // Deliberately not decided here: whether the total clears the subject's own pass mark is
            // the sheet's business, since only it knows the examination. What this knows is the
            // separate rule — theory and practical each passed on their own.
            'isPass' => $complete && $failedGroups === [],
            'failedGroups' => $failedGroups,
            'parts' => $parts,
        ];
    }
}
