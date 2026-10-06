<?php

namespace App\Domain\Identity;

use App\Support\Cms\CmsAcademic;
use Illuminate\Contracts\Session\Session;

/**
 * The Academic Session (kmu-cms Intake, e.g. "2026 Intake") a user is working in.
 *
 * It is the Intake selected in kmu-cms's top bar when they arrive, kept in the session. A question
 * is filed under it unless the author picks another, so the session costs no click. When kmu-cms
 * sent none, or it is not one of the campus's, the campus's newest session is used.
 */
final class ActiveIntake
{
    private const KEY = 'cms_intake_id';

    public function __construct(
        private readonly CmsAcademic $academic,
        private readonly Session $session,
    ) {}

    /** Called when the user arrives from kmu-cms. */
    public function remember(?int $cmsIntakeId): void
    {
        $cmsIntakeId === null ? $this->session->forget(self::KEY) : $this->session->put(self::KEY, $cmsIntakeId);
    }

    public function id(int $branchId): ?int
    {
        $stored = $this->session->get(self::KEY);
        if (is_int($stored) && $this->academic->intakeBelongs($stored, $branchId)) {
            return $stored;
        }

        return $this->academic->intakes($branchId)[0]['id'] ?? null;
    }
}
