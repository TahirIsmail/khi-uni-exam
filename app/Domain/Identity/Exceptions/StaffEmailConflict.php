<?php

namespace App\Domain\Identity\Exceptions;

use RuntimeException;

/**
 * A local account already uses this staff member's email but is linked to someone else (or to no
 * one). It is never taken over automatically; an administrator has to resolve it.
 */
final class StaffEmailConflict extends RuntimeException {}
