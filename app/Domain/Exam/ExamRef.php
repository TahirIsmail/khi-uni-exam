<?php

namespace App\Domain\Exam;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The human reference of an examination, e.g. EX-2026-0001. One counter per year, taken with a row
 * lock inside the caller's transaction, so numbers are never repeated or reused.
 */
final class ExamRef
{
    public static function next(?int $year = null): string
    {
        $year ??= (int) now()->format('Y');
        $counter = 'exam:'.$year;

        if (DB::transactionLevel() === 0) {
            throw new RuntimeException('An examination reference must be taken inside the transaction that saves the examination.');
        }

        DB::table('qb_counters')->insertOrIgnore(['name' => $counter, 'value' => 0]);
        $current = (int) DB::table('qb_counters')->where('name', $counter)->lockForUpdate()->value('value');
        $next = $current + 1;
        DB::table('qb_counters')->where('name', $counter)->update(['value' => $next]);

        return sprintf('EX-%d-%04d', $year, $next);
    }
}
