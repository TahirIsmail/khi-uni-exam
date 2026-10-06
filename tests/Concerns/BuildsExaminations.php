<?php

namespace Tests\Concerns;

use App\Domain\Exam\Models\Examination;
use App\Domain\QuestionBank\Enums\VersionStatus;
use App\Domain\QuestionBank\Models\Question;
use App\Domain\QuestionBank\Models\QuestionType;
use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

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
        // Most tests build blueprints without a question bank behind them; the ones about the bank turn this on.
        config(['exam.blueprint.require_questions_in_bank' => false]);
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
        $this->cmsGrant($this->approverRole, 'exam_papers', 'view');
        $this->cmsGrant($this->approverRole, 'exam_papers_approve', 'view');
        $this->cmsGrant($this->approverRole, 'exam_papers_finalise', 'view');
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
     * An examination whose blueprint is approved (100 marks: 60 one-mark and 20 two-mark questions),
     * written by the officer and approved by the committee member.
     *
     * @param  array<string, mixed>  $blueprint  overrides of blueprintPayload()
     * @param  array<string, mixed>  $examination  overrides of examPayload()
     */
    protected function approvedExam(array $blueprint = [], array $examination = []): Examination
    {
        $exam = $this->newExam($examination);
        $url = "/exams/{$exam->id}/blueprint";

        $this->actingAs($this->setter)->put($url, $this->blueprintPayload($blueprint))->assertSessionHasNoErrors();
        $this->actingAs($this->setter)->post($url.'/submit')->assertSessionHasNoErrors();
        $this->actingAs($this->approver)->post($url.'/approve')->assertSessionHasNoErrors();

        return $exam->refresh();
    }

    /** The ids of the first cognitive and difficulty levels in use. */
    protected function levelId(string $dimension, int $nth = 0): int
    {
        $table = $dimension === 'cognitive' ? 'qb_cognitive_levels' : 'qb_difficulty_levels';

        return (int) DB::table($table)->where('is_active', true)->orderBy('sort_order')->skip($nth)->value('id');
    }

    /**
     * A question in use in the bank: active, filed under an examination type and a topic.
     *
     * @param  list<array{0: string, 1: string, 2: bool}>|null  $options  label, text and whether it is the key
     * @param  list<int>  $mediaIds  pictures shown in the question text
     */
    protected function activeQuestion(
        int $nodeId,
        ?int $typeId = null,
        ?int $examTypeId = null,
        bool $archived = false,
        ?int $branchId = null,
        ?int $cognitive = null,
        ?int $difficulty = null,
        ?string $stem = null,
        ?int $timesUsed = null,
        ?string $lastUsedAt = null,
        ?int $authorId = null,
        ?array $options = null,
        array $mediaIds = [],
    ): QuestionVersion {
        $author = $authorId === null ? User::factory()->create() : User::query()->findOrFail($authorId);
        $question = Question::factory()->create([
            'branch_id' => $branchId ?? $this->branch,
            'course_id' => $this->course,
            'latest_version_no' => 1,
            'is_archived' => $archived,
            'times_used' => $timesUsed ?? 0,
            'last_used_at' => $lastUsedAt,
            'created_by' => $author->id,
        ]);

        $text = $stem ?? 'A man has '.bin2hex(random_bytes(6)).' and asks what to do next.';
        $version = QuestionVersion::factory()->create([
            'question_id' => $question->id,
            'question_type_id' => $typeId ?? $this->typeId(),
            'branch_id' => $branchId ?? $this->branch,
            'course_id' => $this->course,
            'node_id' => $nodeId,
            'exam_type_id' => $examTypeId ?? $this->annual,
            'cognitive_level_id' => $cognitive,
            'difficulty_level_id' => $difficulty,
            'stem' => '<p>'.$text.'</p>',
            'content_hash' => hash('sha256', $text),
            'search_text' => $text,
            // Options and pictures can only be written while it is a draft; the steps to "active" follow.
            'status' => $options === null && $mediaIds === [] ? VersionStatus::Active : VersionStatus::Draft,
            'author_id' => $author->id,
            'created_by' => $author->id,
        ]);

        foreach ($mediaIds as $mediaId) {
            DB::table('qb_version_media')->insert(['version_id' => $version->id, 'media_id' => $mediaId, 'role' => 'stem', 'created_at' => now(), 'updated_at' => now()]);
        }

        if ($options !== null || $mediaIds !== []) {
            foreach ($options ?? [] as $index => [$label, $body, $correct]) {
                DB::table('qb_question_options')->insert([
                    'version_id' => $version->id, 'label' => $label, 'body' => $body, 'is_correct' => $correct,
                    'sort_order' => $index + 1, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            foreach (['submitted', 'under_review', 'approved', 'active'] as $status) {
                DB::table('qb_question_versions')->where('id', $version->id)->update(['status' => $status]);
            }
            $version->refresh();
        }

        $question->update(['active_version_id' => $version->id]);

        return $version;
    }
}
