<?php

namespace App\Domain\Candidate;

use Illuminate\Support\Facades\Hash;
use Random\Randomizer;

/**
 * A candidate's exam PIN: with their candidate number, what ADR-0003 lets them resume an exam with
 * on another computer. Generated once, at check-in or reissue, shown to the invigilator exactly
 * once, and kept only as a hash from then on — the same promise a password is given.
 */
final class CandidatePin
{
    private const LENGTH = 6;

    /** A fresh, random numeric PIN — never predictable from the candidate or the time. */
    public function generate(): string
    {
        $digits = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $digits .= (string) (new Randomizer)->getInt(0, 9);
        }

        return $digits;
    }

    public function hash(string $pin): string
    {
        return Hash::make($pin);
    }

    public function verify(string $pin, string $hash): bool
    {
        return Hash::check($pin, $hash);
    }
}
