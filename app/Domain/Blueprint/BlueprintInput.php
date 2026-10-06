<?php

namespace App\Domain\Blueprint;

/**
 * What the blueprint screen sends: the optional sections, the rows (each pointing at a section by
 * its position in the list) and the two overall mixes.
 */
final readonly class BlueprintInput
{
    /**
     * @param  list<string>  $sections  section names, in order
     * @param  list<array{section: int|null, node_id: int, question_type_id: int, question_count: int, marks_each: float}>  $rows
     * @param  list<array{level_id: int, percent: float}>  $cognitive
     * @param  list<array{level_id: int, percent: float}>  $difficulty
     */
    public function __construct(
        public array $sections,
        public array $rows,
        public array $cognitive,
        public array $difficulty,
    ) {}
}
