<?php

namespace App\Domain\QuestionBank\Support;

use App\Domain\Identity\ActiveIntake;
use App\Domain\QuestionBank\Validation\QuestionContent;
use App\Support\Cms\CmsAcademic;
use Illuminate\Validation\ValidationException;

/**
 * Where a question is filed (KMU's categories): the course and, under it, the subject or topic —
 * or, for BDS and DPT, the course as a whole — and the Academic Session. Shared by the editor and
 * the import, so both refuse the same things with the same words.
 */
final class Filing
{
    public function __construct(
        private readonly CmsAcademic $academic,
        private readonly ActiveIntake $activeIntake,
    ) {}

    /**
     * @return array{branch_id: int, programme_id: int, professional_id: int|null, term_id: int|null, course_id: int, node_id: int|null, discipline_id: int|null}
     */
    public function place(QuestionContent $content): array
    {
        $place = $this->academic->placeOf($content->nodeId, $content->courseId);
        if ($place !== null) {
            return $place;
        }

        $course = $this->academic->placeOfCourse($content->courseId);
        $message = match (true) {
            $course === null => 'Choose the course.',
            $course['status'] === 'retired' => 'That course is retired, so no new questions can be filed under it.',
            $content->nodeId === null => 'Choose the subject of this module.',
            default => 'Choose a subject or topic of this course that questions can be added to.',
        };

        $field = $course === null || $course['status'] === 'retired' ? 'course_id' : 'node_id';

        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * The Academic Session: the one asked for, else the one the question already has, else the one
     * the user is working in.
     */
    public function intake(?int $requested, int $branchId, ?int $current = null): ?int
    {
        if ($requested !== null) {
            if (! $this->academic->intakeBelongs($requested, $branchId)) {
                throw ValidationException::withMessages(['intake_id' => 'Choose an Academic Session of this campus.']);
            }

            return $requested;
        }

        return $current ?? $this->activeIntake->id($branchId);
    }
}
