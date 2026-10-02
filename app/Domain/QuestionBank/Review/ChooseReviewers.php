<?php

namespace App\Domain\QuestionBank\Review;

use App\Domain\QuestionBank\Models\QuestionVersion;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * The reviewers an author asks for while writing a question (KMU): one for each level of review, or
 * nobody ("the least busy"). Any author may choose, but only somebody who may do that level of review
 * of this course, who is not the author, and not the same person at both levels.
 */
final class ChooseReviewers
{
    public function __construct(private readonly ReviewerPool $pool) {}

    public function __invoke(QuestionVersion $version, ?int $subjectReviewerId, ?int $academicReviewerId): void
    {
        if ($subjectReviewerId !== null && $subjectReviewerId === $academicReviewerId) {
            throw ValidationException::withMessages(['academic_reviewer_id' => 'Choose a different person for each level of review.']);
        }

        foreach ([
            'subject_reviewer_id' => [$subjectReviewerId, ReviewStage::Subject],
            'academic_reviewer_id' => [$academicReviewerId, ReviewStage::Academic],
        ] as $field => [$id, $stage]) {
            if ($id === null) {
                continue;
            }
            $reviewer = User::query()->where('is_active', true)->find($id);
            if (! $reviewer instanceof User || ! $this->pool->allows($reviewer, $version, $stage)) {
                throw ValidationException::withMessages([$field => 'That person cannot do the '.mb_strtolower($stage->label()).' of this question.']);
            }
        }

        $version->update([
            'subject_reviewer_id' => $subjectReviewerId,
            'academic_reviewer_id' => $academicReviewerId,
        ]);
    }
}
