<?php

namespace App\Domain\Identity\Authorization;

/** A kmu-cms role held by a staff member (read from v_cms_staff_roles). */
final readonly class CmsRole
{
    public function __construct(
        public int $id,
        public string $name,
        public bool $isSuperAdmin,
    ) {}
}
