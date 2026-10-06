<?php

namespace App\Domain\Blueprint\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\BlueprintInput;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Blueprint\Models\BlueprintRow;
use App\Domain\Blueprint\Models\BlueprintTarget;
use App\Domain\Exam\Models\Examination;
use App\Domain\Exam\Models\Section;
use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Domain\QuestionBank\Models\CognitiveLevel;
use App\Domain\QuestionBank\Models\DifficultyLevel;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves a blueprint as one piece: its sections, its rows and its two mixes replace what was there,
 * all or nothing. A draft may be incomplete — the rows need not add up to the total marks yet, that
 * is checked when it is submitted — but everything it does say has to be true: topics of this
 * examination's course, active types of question, sensible numbers, and a mix that adds up to 100%.
 */
final class SaveBlueprint
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CmsAcademic $academic,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $editor, Examination $examination, BlueprintInput $input): Blueprint
    {
        $this->authorise($editor, $examination);

        $errors = $this->errors($examination, $input);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $nodes = [];
        foreach ($this->academic->curriculum($examination->course_id) as $node) {
            $nodes[$node['id']] = $node;
        }

        return DB::transaction(function () use ($editor, $examination, $input, $nodes): Blueprint {
            /** @var Blueprint $blueprint */
            $blueprint = Blueprint::query()->where('examination_id', $examination->id)->lockForUpdate()->firstOrFail();
            if ($blueprint->status !== BlueprintStatus::Draft) {
                throw ValidationException::withMessages(['blueprint' => 'The blueprint has been submitted. Return it to draft to change it.']);
            }

            $before = $this->summary($blueprint);

            BlueprintRow::query()->where('blueprint_id', $blueprint->id)->delete();
            BlueprintTarget::query()->where('blueprint_id', $blueprint->id)->delete();
            Section::query()->where('examination_id', $examination->id)->delete();

            $sectionIds = [];
            foreach ($input->sections as $index => $name) {
                $sectionIds[$index] = Section::query()->create([
                    'examination_id' => $examination->id,
                    'name' => $name,
                    'sort_order' => $index + 1,
                ])->id;
            }

            foreach ($input->rows as $index => $row) {
                BlueprintRow::query()->create([
                    'blueprint_id' => $blueprint->id,
                    'sort_order' => $index + 1,
                    'section_id' => $row['section'] === null ? null : $sectionIds[$row['section']],
                    'node_id' => $row['node_id'],
                    'discipline_id' => $this->disciplineOf($row['node_id'], $nodes),
                    'question_type_id' => $row['question_type_id'],
                    'question_count' => $row['question_count'],
                    'marks_each' => $row['marks_each'],
                ]);
            }

            foreach (['cognitive' => $input->cognitive, 'difficulty' => $input->difficulty] as $dimension => $targets) {
                foreach ($targets as $target) {
                    BlueprintTarget::query()->create([
                        'blueprint_id' => $blueprint->id,
                        'dimension' => $dimension,
                        'level_id' => $target['level_id'],
                        'percent' => $target['percent'],
                    ]);
                }
            }

            $blueprint->update(['updated_by' => $editor->id]);

            $this->audit->record(
                'blueprint.saved',
                'blueprint',
                $blueprint->id,
                $before,
                $this->summary($blueprint->refresh()),
                null,
                $editor,
                $examination->branch_id,
            );

            return $blueprint;
        });
    }

    private function authorise(User $editor, Examination $examination): void
    {
        if (! $this->access->allows($editor, 'exam.blueprint.manage', new ScopeTarget($examination->branch_id, $examination->programme_id, $examination->professional_id, $examination->course_id))) {
            throw new AuthorizationException('You cannot write blueprints for this course.');
        }
    }

    /**
     * @return array<string, string>
     */
    private function errors(Examination $examination, BlueprintInput $input): array
    {
        $errors = [];
        $limits = (array) config('exam.blueprint');

        if (count($input->sections) > (int) $limits['max_sections']) {
            $errors['sections'] = 'A paper can have at most '.$limits['max_sections'].' sections.';
        }
        $seen = [];
        foreach ($input->sections as $index => $name) {
            $key = mb_strtolower($name);
            if (isset($seen[$key])) {
                $errors["sections.{$index}"] = 'Two sections are called '.$name.'.';
            }
            $seen[$key] = true;
        }

        if (count($input->rows) > (int) $limits['max_rows']) {
            $errors['rows'] = 'A blueprint can have at most '.$limits['max_rows'].' rows.';
        }

        // 0 is the whole course: questions from anywhere in it (CandidatePool reads it the same way).
        $topics = [0, ...array_column($this->academic->curriculum($examination->course_id), 'id')];
        $types = QuestionType::query()->where('is_active', true)->pluck('id')->all();
        $keys = [];
        foreach ($input->rows as $index => $row) {
            if (! in_array($row['node_id'], $topics, true)) {
                $errors["rows.{$index}.node_id"] = 'Choose a topic of this examination\'s course.';
            }
            if (! in_array($row['question_type_id'], $types, true)) {
                $errors["rows.{$index}.question_type_id"] = 'Choose a type of question.';
            }
            if ($row['section'] !== null && ! isset($input->sections[$row['section']])) {
                $errors["rows.{$index}.section"] = 'Choose one of the sections above.';
            }

            $key = implode('|', [$row['node_id'], $row['question_type_id'], number_format($row['marks_each'], 2, '.', ''), $row['section'] ?? '-']);
            if (isset($keys[$key])) {
                $errors["rows.{$index}.node_id"] = 'The same topic, type, marks and section is already a row: raise its number of questions instead.';
            }
            $keys[$key] = true;
        }

        foreach (['cognitive' => CognitiveLevel::class, 'difficulty' => DifficultyLevel::class] as $dimension => $model) {
            $targets = $dimension === 'cognitive' ? $input->cognitive : $input->difficulty;
            if ($targets === []) {
                continue;
            }

            $levels = $model::query()->where('is_active', true)->pluck('id')->all();
            $ids = [];
            foreach ($targets as $index => $target) {
                if (! in_array($target['level_id'], $levels, true) || in_array($target['level_id'], $ids, true)) {
                    $errors["{$dimension}.{$index}.level_id"] = 'Choose each level once.';
                }
                $ids[] = $target['level_id'];
            }
            if (abs(array_sum(array_column($targets, 'percent')) - 100) > 0.01) {
                $errors[$dimension] = 'The '.($dimension === 'cognitive' ? 'cognitive level' : 'difficulty level').' mix has to add up to 100%.';
            }
        }

        return $errors;
    }

    /**
     * The discipline of a topic: its own, or the nearest one above it.
     *
     * @param  array<int, array{id: int, parent_id: int|null, discipline_id: int|null}>  $nodes
     */
    private function disciplineOf(int $nodeId, array $nodes): ?int
    {
        for ($guard = 0; $nodeId !== 0 && $guard < 20; $guard++) {
            $node = $nodes[$nodeId] ?? null;
            if ($node === null) {
                return null;
            }
            if ($node['discipline_id'] !== null) {
                return $node['discipline_id'];
            }
            $nodeId = (int) $node['parent_id'];
        }

        return null;
    }

    /**
     * @return array<string, int|float>
     */
    private function summary(Blueprint $blueprint): array
    {
        $rows = $blueprint->rows()->get();

        return [
            'rows' => $rows->count(),
            'questions' => (int) $rows->sum('question_count'),
            'marks' => round((float) $rows->sum(fn (BlueprintRow $row): float => $row->marks()), 2),
            'sections' => Section::query()->where('examination_id', $blueprint->examination_id)->count(),
            'targets' => BlueprintTarget::query()->where('blueprint_id', $blueprint->id)->count(),
        ];
    }
}
