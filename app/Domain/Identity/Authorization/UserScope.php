<?php

namespace App\Domain\Identity\Authorization;

/** An exam access limit set in kmu-cms: one programme, professional or course. */
final readonly class UserScope
{
    public function __construct(
        public string $type,
        public int $id,
    ) {}
}
