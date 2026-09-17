<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * A single sign-on ticket from kmu-cms was refused. The reason is logged for administrators;
 * the user only sees a generic message, so the response never tells an attacker which check failed.
 */
final class InvalidCmsTicket extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $cmsStaffId = null)
    {
        parent::__construct("CMS sign-in ticket refused: {$reason}");
    }
}
