<?php

namespace App\Support\Cms;

use Illuminate\Support\Facades\DB;

/**
 * Settings of this module that a Super Admin manages in kmu-cms (Question Bank & Exams → Exam
 * Module Settings). Read once per request.
 */
final class CmsSettings
{
    private ?bool $mfaEnabled = null;

    /** Whether everyone must use an authenticator app. Off unless turned on in kmu-cms. */
    public function mfaEnabled(): bool
    {
        return $this->mfaEnabled ??= (int) DB::connection('cms')->table('v_cms_exam_settings')->value('kmu_assess_mfa_enabled') === 1;
    }
}
