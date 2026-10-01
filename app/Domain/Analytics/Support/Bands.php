<?php

namespace App\Domain\Analytics\Support;

/**
 * The plain words beside each figure on the analysis screen, as KMU's requirements explain them
 * ("in plain terms"). The thresholds are in config/exam.php (analytics).
 *
 * Each band is a key (for colour) and the words shown.
 */
final class Bands
{
    /**
     * Difficulty index: the share of candidates who got the question right. Closer to 1 is easier.
     *
     * @return array{key: string, label: string}|null
     */
    public static function difficulty(?float $p): ?array
    {
        if ($p === null) {
            return null;
        }

        return match (true) {
            $p > (float) config('exam.analytics.difficulty.too_easy_above') => ['key' => 'warn', 'label' => 'Too easy'],
            $p < (float) config('exam.analytics.difficulty.too_hard_below') => ['key' => 'warn', 'label' => 'Too hard'],
            default => ['key' => 'good', 'label' => 'Moderate'],
        };
    }

    /**
     * Discrimination index: how well the question tells strong candidates from weak ones (-1 to +1).
     *
     * @return array{key: string, label: string}|null
     */
    public static function discrimination(?float $d): ?array
    {
        if ($d === null) {
            return null;
        }

        return match (true) {
            $d < 0 => ['key' => 'bad', 'label' => 'Red flag: check the key'],
            $d >= (float) config('exam.analytics.discrimination.good_from') => ['key' => 'good', 'label' => 'Good'],
            $d >= (float) config('exam.analytics.discrimination.acceptable_from') => ['key' => 'ok', 'label' => 'Acceptable'],
            default => ['key' => 'warn', 'label' => 'Poor'],
        };
    }

    /**
     * KR-20 / Cronbach's alpha: how consistent the whole examination is (0 to 1).
     *
     * @return array{key: string, label: string}|null
     */
    public static function reliability(?float $coefficient): ?array
    {
        if ($coefficient === null) {
            return null;
        }

        return match (true) {
            $coefficient >= (float) config('exam.analytics.reliability.good_from') => ['key' => 'good', 'label' => 'Good'],
            $coefficient >= (float) config('exam.analytics.reliability.acceptable_from') => ['key' => 'ok', 'label' => 'Acceptable'],
            default => ['key' => 'bad', 'label' => 'Low'],
        };
    }
}
