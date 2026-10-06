<?php

namespace App\Domain\Paper\Models;

/**
 * A blueprint row as the paper sees it: how many questions of one type, worth this much each, from
 * this topic and everything below it, in this section. The four things together are what makes a row
 * the same row when a blueprint has been saved again, so an item finds its row by them.
 */
final readonly class PaperSlot
{
    public function __construct(
        public int $nodeId,
        public int $typeId,
        public float $marks,
        public ?string $section,
        public int $count,
    ) {}

    public static function keyOf(int $nodeId, int $typeId, float $marks, ?string $section): string
    {
        return implode('|', [$nodeId, $typeId, number_format($marks, 2, '.', ''), $section ?? '']);
    }

    public function key(): string
    {
        return self::keyOf($this->nodeId, $this->typeId, $this->marks, $this->section);
    }
}
