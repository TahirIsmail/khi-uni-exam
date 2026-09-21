<?php

namespace App\Domain\Exam\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Exam\ExaminationInput;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Changes an examination's details. While its blueprint is a draft everything can change; once the
 * blueprint is submitted or approved, the course, examination type and total marks are what the
 * blueprint was checked against and stay as they are (the database refuses them too). The title,
 * date and time, duration, pass mark, marking and instructions can still be corrected.
 */
final class UpdateExamination
{
    public function __construct(
        private readonly ResolveExaminationPlace $place,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $editor, Examination $examination, ExaminationInput $input): Examination
    {
        $examination->loadMissing('blueprint');
        $blueprintEditable = $examination->blueprint === null || $examination->blueprint->status === BlueprintStatus::Draft;

        $place = ($this->place)($editor, 'exam.create', $examination->branch_id, $input->courseId, $input->examTypeId, $input->intakeId);

        if (! $blueprintEditable) {
            $moved = $input->courseId !== $examination->course_id
                || $input->examTypeId !== $examination->exam_type_id
                || abs($input->totalMarks - $examination->total_marks) > 0.004;
            if ($moved) {
                throw ValidationException::withMessages(['course_id' => 'The blueprint has been submitted, so the course, examination and total marks are fixed. Return the blueprint to draft to change them.']);
            }
        }

        // The rows of a blueprint are topics of one course; they cannot follow the examination to another.
        if ($input->courseId !== $examination->course_id && $examination->blueprint !== null && $examination->blueprint->rows()->exists()) {
            throw ValidationException::withMessages(['course_id' => 'The blueprint already has rows from the topics of the current course. Remove them in the blueprint first, then change the course.']);
        }

        $title = $input->title !== null && trim($input->title) !== '' ? trim($input->title) : $examination->title;

        return DB::transaction(function () use ($editor, $examination, $input, $place, $title): Examination {
            $before = $this->summary($examination);

            $examination->update([
                'title' => $title,
                'programme_id' => $place['programme_id'],
                'professional_id' => $place['professional_id'],
                'term_id' => $place['term_id'],
                'course_id' => $place['course_id'],
                'exam_type_id' => $input->examTypeId,
                'intake_id' => $input->intakeId,
                'starts_at' => $input->startsAt,
                'duration_minutes' => $input->durationMinutes,
                'total_marks' => $input->totalMarks,
                'pass_percentage' => $input->passPercentage,
                'negative_marking' => $input->negativeMarking,
                'negative_fraction' => $input->negativeMarking ? $input->negativeFraction : null,
                'instructions' => $input->instructions,
                'updated_by' => $editor->id,
            ]);

            $after = $this->summary($examination->refresh());
            $changed = array_keys(array_filter($after, fn (mixed $value, string $key): bool => $value !== $before[$key], ARRAY_FILTER_USE_BOTH));

            if ($changed !== []) {
                $this->audit->record(
                    'exam.updated',
                    'examination',
                    $examination->id,
                    array_intersect_key($before, array_flip($changed)),
                    array_intersect_key($after, array_flip($changed)),
                    null,
                    $editor,
                    $examination->branch_id,
                );
            }

            return $examination;
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Examination $examination): array
    {
        return [
            'title' => $examination->title,
            'course_id' => $examination->course_id,
            'exam_type_id' => $examination->exam_type_id,
            'intake_id' => $examination->intake_id,
            'starts_at' => $examination->starts_at?->format('Y-m-d H:i:s'),
            'duration_minutes' => $examination->duration_minutes,
            'total_marks' => $examination->total_marks,
            'pass_percentage' => $examination->pass_percentage,
            'negative_marking' => $examination->negative_marking,
            'negative_fraction' => $examination->negative_fraction,
            'instructions' => $examination->instructions,
        ];
    }
}
