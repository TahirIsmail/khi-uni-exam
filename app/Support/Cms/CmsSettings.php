<?php

namespace App\Support\Cms;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Settings of this module that a Super Admin manages in kmu-cms (Question Bank & Exams → Exam
 * Module Settings). Read once per request.
 */
final class CmsSettings
{
    private ?stdClass $row = null;

    /** Whether everyone must use an authenticator app. Off unless turned on in kmu-cms. */
    public function mfaEnabled(): bool
    {
        return $this->int('kmu_assess_mfa_enabled', 0) === 1;
    }

    /** How many reviewers must submit a review before a question can be approved. */
    public function reviewsRequired(): int
    {
        return max(1, min(5, $this->int('kmu_assess_reviews_required', 1)));
    }

    /** How long a reviewer has, used for the due date of an assignment. */
    public function reviewDays(): int
    {
        return max(1, min(60, $this->int('kmu_assess_review_days', 7)));
    }

    /** Whether an approved question becomes usable in exams straight away. */
    public function autoActivate(): bool
    {
        return $this->int('kmu_assess_auto_activate', 1) === 1;
    }

    /** Whether authors see who reviewed their question. The comments are always shown. */
    public function reviewerAnonymous(): bool
    {
        return $this->int('kmu_assess_reviewer_anonymous', 0) === 1;
    }

    private function int(string $column, int $default): int
    {
        $row = $this->row ??= DB::connection('cms')->table('v_cms_exam_settings')->first() ?? new stdClass;

        return isset($row->{$column}) ? (int) $row->{$column} : $default;
    }
}
