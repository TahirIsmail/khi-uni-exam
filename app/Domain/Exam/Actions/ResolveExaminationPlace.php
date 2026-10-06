<?php

namespace App\Domain\Exam\Actions;

use App\Domain\Identity\Authorization\AccessControl;
use App\Domain\Identity\Authorization\ScopeTarget;
use App\Models\User;
use App\Support\Cms\CmsAcademic;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Where an examination sits, worked out from the course the user chose and checked against kmu-cms:
 * the course is active, in the campus being worked in and attached to a year; the examination type
 * belongs to the programme's calendar; the academic session is one of the campus's; and the user
 * has the right for that programme, year and course.
 */
final class ResolveExaminationPlace
{
    public function __construct(
        private readonly AccessControl $access,
        private readonly CmsAcademic $academic,
    ) {}

    /**
     * @return array{programme_id: int, professional_id: int, term_id: int|null, course_id: int, course_label: string}
     */
    public function __invoke(User $user, string $permission, int $branchId, int $courseId, int $examTypeId, ?int $intakeId): array
    {
        $place = $this->academic->placeOfCourse($courseId);
        if ($place === null || $place['status'] !== 'active') {
            throw ValidationException::withMessages(['course_id' => 'Choose a Course ID that is in use.']);
        }
        if ($place['branch_id'] !== $branchId) {
            throw ValidationException::withMessages(['course_id' => 'That course belongs to another campus.']);
        }
        if ($place['professional_id'] === null) {
            throw ValidationException::withMessages(['course_id' => 'That course is not attached to a year yet. Attach it in the CMS: Academics → Courses.']);
        }
        if (! $this->academic->examTypeFits($examTypeId, $place['programme_id'])) {
            throw ValidationException::withMessages(['exam_type_id' => 'That examination is not held by this programme (Annual and Supplementary are for annual programmes, Regular and Retake for semester programmes).']);
        }
        if ($intakeId !== null && ! in_array($intakeId, array_column($this->academic->intakes($branchId), 'id'), true)) {
            throw ValidationException::withMessages(['intake_id' => 'Choose one of the academic sessions of this campus.']);
        }
        if (! $this->access->allows($user, $permission, new ScopeTarget($branchId, $place['programme_id'], $place['professional_id'], $courseId))) {
            throw new AuthorizationException('You cannot set examinations for this course.');
        }

        return [
            'programme_id' => $place['programme_id'],
            'professional_id' => $place['professional_id'],
            'term_id' => $place['term_id'],
            'course_id' => $courseId,
            'course_label' => $place['label'],
        ];
    }
}
