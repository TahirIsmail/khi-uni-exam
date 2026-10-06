<?php

namespace App\Domain\QuestionBank\Support;

/**
 * Word-by-word comparison of two pieces of text, used to show what changed between two versions of
 * a question. A longest-common-subsequence walk gives the shortest set of removals and additions,
 * which is what a reader expects to see highlighted.
 */
final class TextDiff
{
    /**
     * @return list<array{type: 'same'|'removed'|'added', text: string}>
     */
    public static function words(string $before, string $after): array
    {
        $old = self::split($before);
        $new = self::split($after);

        $lengths = self::lengths($old, $new);
        $parts = [];

        $i = 0;
        $j = 0;
        while ($i < count($old) && $j < count($new)) {
            if ($old[$i] === $new[$j]) {
                $parts[] = ['type' => 'same', 'text' => $old[$i]];
                $i++;
                $j++;
            } elseif ($lengths[$i + 1][$j] >= $lengths[$i][$j + 1]) {
                $parts[] = ['type' => 'removed', 'text' => $old[$i]];
                $i++;
            } else {
                $parts[] = ['type' => 'added', 'text' => $new[$j]];
                $j++;
            }
        }
        for (; $i < count($old); $i++) {
            $parts[] = ['type' => 'removed', 'text' => $old[$i]];
        }
        for (; $j < count($new); $j++) {
            $parts[] = ['type' => 'added', 'text' => $new[$j]];
        }

        return self::merge($parts);
    }

    public static function changed(string $before, string $after): bool
    {
        return trim($before) !== trim($after);
    }

    /**
     * @return list<string>
     */
    private static function split(string $text): array
    {
        $words = preg_split('/(\s+)/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);

        return $words === false ? [] : $words;
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return array<int, array<int, int>>
     */
    private static function lengths(array $old, array $new): array
    {
        $lengths = array_fill(0, count($old) + 1, array_fill(0, count($new) + 1, 0));

        for ($i = count($old) - 1; $i >= 0; $i--) {
            for ($j = count($new) - 1; $j >= 0; $j--) {
                $lengths[$i][$j] = $old[$i] === $new[$j]
                    ? $lengths[$i + 1][$j + 1] + 1
                    : max($lengths[$i + 1][$j], $lengths[$i][$j + 1]);
            }
        }

        return $lengths;
    }

    /**
     * Neighbouring words of the same kind read better as one piece.
     *
     * @param  list<array{type: 'same'|'removed'|'added', text: string}>  $parts
     * @return list<array{type: 'same'|'removed'|'added', text: string}>
     */
    private static function merge(array $parts): array
    {
        $merged = [];
        foreach ($parts as $part) {
            $last = array_key_last($merged);
            if ($last !== null && $merged[$last]['type'] === $part['type']) {
                $merged[$last]['text'] .= ' '.$part['text'];

                continue;
            }
            $merged[] = $part;
        }

        return $merged;
    }
}
