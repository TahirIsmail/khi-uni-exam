<?php

namespace App\Domain\Identity\Authorization;

/** Where a user's permissions apply: everywhere ("all"), or one programme, professional or course. */
final readonly class UserScope
{
    public function __construct(
        public string $type,
        public ?int $id,
    ) {}
}
