<?php

namespace App\Domain\Marking\Support;

use App\Domain\QuestionBank\Models\QuestionAnswer;
use Illuminate\Support\Collection;

/**
 * Whether a candidate's typed text matches one of a question's accepted answers (short answer,
 * numerical, or a cloze blank) — the same match_mode the question editor already offers.
 */
final class TypedAnswerMatch
{
    /**
     * @param  Collection<int, QuestionAnswer>  $accepted  ordered by sort_order: the first match wins
     */
    public static function bestFraction(Collection $accepted, ?string $given): float
    {
        if ($given === null || trim($given) === '') {
            return 0.0;
        }

        foreach ($accepted as $answer) {
            if (self::matches($answer, $given)) {
                return $answer->marks_fraction;
            }
        }

        return 0.0;
    }

    private static function matches(QuestionAnswer $answer, string $given): bool
    {
        if ($answer->match_mode === 'numeric') {
            if (! is_numeric(trim($given)) || $answer->numeric_value === null) {
                return false;
            }
            $value = (float) trim($given);
            $tolerance = $answer->tolerance ?? 0.0;
            $allowed = $answer->tolerance_type === 'relative' ? abs($answer->numeric_value) * $tolerance : $tolerance;

            return abs($value - $answer->numeric_value) <= $allowed;
        }

        $expected = (string) $answer->answer_text;
        $candidate = $given;
        if (! $answer->case_sensitive) {
            $expected = mb_strtolower($expected);
            $candidate = mb_strtolower($candidate);
        }

        return match ($answer->match_mode) {
            'contains' => str_contains($candidate, $expected),
            'regex' => @preg_match('/'.$answer->answer_text.'/'.($answer->case_sensitive ? '' : 'i'), $given) === 1,
            default => trim($candidate) === trim($expected),
        };
    }
}
