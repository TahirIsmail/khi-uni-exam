<?php

namespace App\Domain\Marking\Queries;

use App\Domain\Marking\Enums\MarkSource;
use App\Domain\Marking\Models\ItemMark;
use Illuminate\Support\Collection;

/**
 * The one mark that counts for an item, out of however many sources have weighed in — the same
 * priority everywhere, so step 21's results never disagree with the marking screens about which
 * mark is final.
 */
final class FinalMark
{
    /**
     * @param  Collection<int, ItemMark>  $marksForItem  every mark recorded for one item
     */
    public static function of(Collection $marksForItem, bool $requireDoubleMarking): ?ItemMark
    {
        $bySource = $marksForItem->keyBy(fn (ItemMark $m): string => $m->source->value);

        // A re-key corrects the question itself, so it outranks every marking decision regardless
        // of whether double-marking was required.
        $priority = $requireDoubleMarking
            ? [MarkSource::Rekeyed, MarkSource::Adjudicator, MarkSource::Final, MarkSource::Auto]
            : [MarkSource::Rekeyed, MarkSource::Adjudicator, MarkSource::Final, MarkSource::Examiner1, MarkSource::Auto];

        foreach ($priority as $source) {
            $mark = $bySource->get($source->value);
            if ($mark !== null) {
                return $mark;
            }
        }

        return null;
    }

    /**
     * Whether an item is still waiting for something before it has a final mark — whether that is
     * the first mark of any kind, the second examiner, or an adjudicator's decision.
     *
     * @param  Collection<int, ItemMark>  $marksForItem
     */
    public static function isPending(Collection $marksForItem, bool $requireDoubleMarking): bool
    {
        return self::of($marksForItem, $requireDoubleMarking) === null;
    }
}
