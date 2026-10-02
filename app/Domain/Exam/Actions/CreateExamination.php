<?php

namespace App\Domain\Exam\Actions;

use App\Domain\Audit\AuditLogger;
use App\Domain\Blueprint\Enums\BlueprintStatus;
use App\Domain\Blueprint\Models\Blueprint;
use App\Domain\Exam\Enums\ExaminationStatus;
use App\Domain\Exam\ExaminationInput;
use App\Domain\Exam\ExaminationTitle;
use App\Domain\Exam\ExamRef;
use App\Domain\Exam\Models\Examination;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates an examination and, with it, its empty blueprint, in the campus the user is working in
 * and for a course they may set examinations for. Nothing is chosen from the question bank yet:
 * that comes after the blueprint is approved.
 */
final class CreateExamination
{
    public function __construct(
        private readonly ResolveExaminationPlace $place,
        private readonly ExaminationTitle $titles,
        private readonly CmsAcademic $academic,
        private readonly AuditLogger $audit,
    ) {}

    public function __invoke(User $creator, int $branchId, ExaminationInput $input): Examination
    {
        $place = ($this->place)($creator, 'exam.create', $branchId, $input->courseId, $input->examTypeId, $input->intakeId);
        if (! $this->academic->programmeInUse($place['programme_id'])) {
            throw ValidationException::withMessages(['course_id' => 'This program is switched off in the CMS, so no new examinations are created for it.']);
        }

        $title = $input->title !== null && trim($input->title) !== ''
            ? trim($input->title)
            : $this->titles->for($branchId, $place['programme_id'], $place['professional_id'], $place['term_id'], $input->examTypeId, $place['course_label'], $input->startsAt ?? now());

        return DB::transaction(function () use ($creator, $branchId, $input, $place, $title): Examination {
            $examination = Examination::query()->create([
                'public_ref' => ExamRef::next(),
                'branch_id' => $branchId,
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
                'status' => ExaminationStatus::Draft,
                'created_by' => $creator->id,
            ]);

            Blueprint::query()->create([
                'examination_id' => $examination->id,
                'branch_id' => $branchId,
                'status' => BlueprintStatus::Draft,
                'created_by' => $creator->id,
            ]);

            $this->audit->record('exam.created', 'examination', $examination->id, null, [
                'public_ref' => $examination->public_ref,
                'title' => $examination->title,
                'course_id' => $examination->course_id,
                'exam_type_id' => $examination->exam_type_id,
                'total_marks' => $examination->total_marks,
                'duration_minutes' => $examination->duration_minutes,
                'pass_percentage' => $examination->pass_percentage,
            ], null, $creator, $branchId);

            return $examination;
        });
    }
}
