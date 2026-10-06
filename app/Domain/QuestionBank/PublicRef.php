<?php

namespace App\Domain\QuestionBank;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The human reference of a question, e.g. Q-2026-000123. One counter per year, taken with a row
 * lock inside the caller's transaction, so numbers are never repeated or reused.
 */
final class PublicRef
{
    public static function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $counter = 'question:'.$year;

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('A question reference must be taken inside the transaction that saves the question.');
        }

        DB::table('qb_counters')->insertOrIgnore(['name' => $counter, 'value' => 0]);
        $current = (int) DB::table('qb_counters')->where('name', $counter)->lockForUpdate()->value('value');
        $next = $current + 1;
        DB::table('qb_counters')->where('name', $counter)->update(['value' => $next]);

        return sprintf('Q-%d-%06d', $year, $next);
    }
}
