<?php

namespace Tests\Concerns;

use App\Domain\Exam\Models\Examination;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;

/**
 * A campus, a programme with a year, a course with two topics, and the people who work on
 * examinations: an examination officer who sets one up and writes its blueprint, and a committee
 * member who approves blueprints. Needs InteractsWithCms.
 */
trait BuildsExaminations
{
    protected function examWorld(): void
    {
        $this->shareCmsConnection();
        $this->cmsExamSettings();
        $this->branch = $this->cmsBranch('Main Campus');
        $this->programme = $this->cmsProgramme($this->branch, 'MBBS');
        $this->professional = $this->cmsProfessional($this->programme);
        $this->course = $this->cmsCourse($this->programme, $this->professional, 'CVS');
        $this->discipline = $this->cmsDiscipline('Physiology');
        $this->node = $this->cmsCurriculumNode($this->course, $this->programme, 'Acute coronary syndrome', disciplineId: $this->discipline);
        $this->otherNode = $this->cmsCurriculumNode($this->course, $this->programme, 'Heart failure');
        $this->annual = $this->cmsExamType('annual');

        // An examination officer sets one up and writes its blueprint.
        $this->setterRole = $this->cmsRole('Exam officer');
        $this->cmsGrant($this->setterRole, 'exam_papers', 'view', 'add', 'edit');
        $this->cmsGrant($this->setterRole, 'exam_blueprints', 'view', 'add', 'edit');
        $this->setter = $this->staffUser([$this->setterRole], $this->branch);

        // The examination committee approves blueprints.
        $this->approverRole = $this->cmsRole('Examination committee');
        $this->cmsGrant($this->approverRole, 'exam_blueprints', 'view');
        $this->cmsGrant($this->approverRole, 'exam_blueprints_approve', 'view');
        $this->approver = $this->staffUser([$this->approverRole], $this->branch);
    }

    /**
     * What the examination form sends.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function examPayload(array $overrides = []): array
    {
        return array_replace([
            'title' => 'First Professional Annual Examination 2026 — Cardiovascular',
            'course_id' => $this->course,
            'exam_type_id' => $this->annual,
            'starts_at' => '2026-10-05T09:00',
            'duration_minutes' => 180,
            'total_marks' => 100,
            'pass_percentage' => 50,
            'negative_marking' => false,
            'instructions' => "Answer every question.\nThere is one best answer.",
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function newExam(array $overrides = []): Examination
    {
        $this->actingAs($this->setter)->post('/exams', $this->examPayload($overrides))->assertRedirect();

        return Examination::query()->latest('id')->firstOrFail();
    }

    protected function typeId(string $code = 'single_best_answer'): int
    {
        return (int) QuestionType::query()->where('code', $code)->value('id');
    }

    /**
     * A blueprint for a paper of 100 marks: 60 one-mark questions from one topic and 20 two-mark
     * questions from the other.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function blueprintPayload(array $overrides = []): array
    {
        return array_replace([
            'sections' => [],
            'rows' => [
                ['section' => null, 'node_id' => $this->node, 'question_type_id' => $this->typeId(), 'question_count' => 60, 'marks_each' => 1],
                ['section' => null, 'node_id' => $this->otherNode, 'question_type_id' => $this->typeId(), 'question_count' => 20, 'marks_each' => 2],
            ],
            'cognitive' => [],
            'difficulty' => [],
        ], $overrides);
    }

    /**
     * A question in use in the bank: active, filed under an examination type and a topic.
     */
    protected function activeQuestion(int $nodeId, ?int $typeId = null, ?int $examTypeId = null, bool $archived = false, ?int $branchId = null): QuestionVersion
    {
        $author = User::factory()->create();
        $question = Question::factory()->create([
            'branch_id' => $branchId ?? $this->branch,
            'course_id' => $this->course,
            'latest_version_no' => 1,
            'is_archived' => $archived,
            'created_by' => $author->id,
        ]);
        $version = QuestionVersion::factory()->create([
            'question_id' => $question->id,
            'question_type_id' => $typeId ?? $this->typeId(),
            'branch_id' => $branchId ?? $this->branch,
            'course_id' => $this->course,
            'node_id' => $nodeId,
            'exam_type_id' => $examTypeId ?? $this->annual,
            'status' => VersionStatus::Active,
            'author_id' => $author->id,
            'created_by' => $author->id,
        ]);
        $question->update(['active_version_id' => $version->id]);

        return $version;
    }
}
