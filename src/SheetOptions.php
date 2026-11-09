<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

/**
 * Per-sheet layout settings for writers.
 */
final class SheetOptions
{
    /**
     * @param  array<int, float>  $columnWidths  1-based column => width in characters
     */
    public function __construct(
        public array $columnWidths = [],
        public int $freezeRows = 0,
        public bool $autoFilter = false,
    ) {}
}
