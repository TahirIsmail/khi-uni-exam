<?php

namespace App\Domain\Blueprint;

use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Blueprint\Models\BlueprintRow;
use App\Domain\Exam\Models\Examination;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Support\Cms\CmsAcademic;

/**
 * Reads a blueprint against its examination and the question bank. What it finds is of two kinds:
 *
 *  - blockers: things that make the blueprint wrong as it stands — no rows, planned marks that do not
 *    add up to the examination's total marks, a topic that has left the curriculum, a mix that does
 *    not add up to 100%. A blueprint with blockers cannot be submitted.
 *  - warnings: things worth knowing. Unless the institution has turned it off, the question bank
 *    holding fewer questions than the rows ask for is a blocker too, because a paper could not be
 *    built to the blueprint.
 */
final class BlueprintChecker
{
    public function __construct(
        private readonly BlueprintAvailability $availability,
        private readonly CmsAcademic $academic,
    ) {}

    /**
     * @return array{
     *     plannedQuestions: int,
     *     plannedMarks: float,
     *     totalMarks: float,
     *     difference: float,
     *     isBalanced: bool,
     *     blockers: list<string>,
     *     warnings: list<string>,
     *     targets: array{cognitive: float, difficulty: float}
     * }
     */
    public function check(Examination $examination, Blueprint $blueprint): array
    {
        $rows = $blueprint->rows()->get();
        $targets = $blueprint->targets()->get();

        $plannedQuestions = (int) $rows->sum('question_count');
        $plannedMarks = round((float) $rows->sum(fn (BlueprintRow $row): float => $row->marks()), 2);
        $difference = round($plannedMarks - $examination->total_marks, 2);
        $isBalanced = abs($difference) <= (float) config('exam.blueprint.marks_tolerance');

        $blockers = [];
        $warnings = [];

        if ($rows->isEmpty()) {
            $blockers[] = 'Add at least one row: which topics the paper draws on, and how many questions of each type.';
        } elseif (! $isBalanced) {
            $blockers[] = sprintf(
                'The rows add up to %s marks but the examination is out of %s: %s.',
                $this->number($plannedMarks),
                $this->number($examination->total_marks),
                $difference > 0 ? 'take '.$this->number($difference).' off' : 'add '.$this->number(-$difference),
            );
        }

        $sums = ['cognitive' => 0.0, 'difficulty' => 0.0];
        foreach (['cognitive', 'difficulty'] as $dimension) {
            $set = $targets->where('dimension', $dimension);
            $sums[$dimension] = round((float) $set->sum('percent'), 2);
            if ($set->isNotEmpty() && abs($sums[$dimension] - 100) > 0.01) {
                $blockers[] = sprintf('The %s mix adds up to %s%%, not 100%%.', $dimension === 'cognitive' ? 'cognitive level' : 'difficulty level', $this->number($sums[$dimension]));
            }
        }

        // The topics of the course as they are now: the curriculum can change under a saved blueprint.
        $topics = [];
        $parents = [];
        foreach ($this->academic->curriculum($examination->course_id) as $node) {
            $topics[$node['id']] = $node['name'];
            $parents[$node['id']] = $node['parent_id'];
        }
        $typeNames = QuestionType::query()->pluck('name', 'id');
        $matrix = $this->availability->matrix($examination->branch_id, $examination->course_id, $examination->exam_type_id);

        foreach ($rows as $row) {
            if (! isset($topics[$row->node_id])) {
                $blockers[] = 'A row uses a topic that is no longer in the curriculum: remove it or choose another.';
            }
        }

        // What the bank cannot give. A heading counts everything under it, so the demand on a topic is
        // the questions its own rows ask for and those of the rows below it: the topics form a tree,
        // and a paper can be built exactly when no topic is asked for more than it holds.
        $shortages = [];
        foreach ($rows->unique(fn (BlueprintRow $row): string => $row->node_id.'-'.$row->question_type_id) as $row) {
            if (! isset($topics[$row->node_id])) {
                continue;
            }

            $wanted = (int) $rows->filter(fn (BlueprintRow $other): bool => $other->question_type_id === $row->question_type_id
                && $this->isWithin($other->node_id, $row->node_id, $parents))->sum('question_count');
            $available = $matrix[$row->node_id][$row->question_type_id] ?? 0;

            if ($wanted > $available) {
                $shortages[] = sprintf(
                    '%s, %s: %d wanted, %d in the question bank — %d more to write or import.',
                    $topics[$row->node_id],
                    (string) ($typeNames[$row->question_type_id] ?? 'Questions'),
                    $wanted,
                    $available,
                    $wanted - $available,
                );
            }
        }

        if (config('exam.blueprint.require_questions_in_bank') === true) {
            array_push($blockers, ...$shortages);
        } else {
            array_push($warnings, ...$shortages);
        }

        return [
            'plannedQuestions' => $plannedQuestions,
            'plannedMarks' => $plannedMarks,
            'totalMarks' => $examination->total_marks,
            'difference' => $difference,
            'isBalanced' => $isBalanced,
            'blockers' => array_values(array_unique($blockers)),
            'warnings' => $warnings,
            'targets' => ['cognitive' => $sums['cognitive'], 'difficulty' => $sums['difficulty']],
        ];
    }

    /**
     * Whether a topic is the other one or sits below it.
     *
     * @param  array<int, int|null>  $parents  topic id => its parent's id
     */
    private function isWithin(int $nodeId, int $ancestorId, array $parents): bool
    {
        for ($guard = 0; $nodeId !== 0 && $guard < 20; $guard++) {
            if ($nodeId === $ancestorId) {
                return true;
            }
            $nodeId = (int) ($parents[$nodeId] ?? 0);
        }

        return false;
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
