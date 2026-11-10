<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Inspection;

use IntrAgile\Spreadsheet\Format;

/**
 * What a bounded scan found out about a file.
 */
final readonly class Inspection
{
    /**
     * @param  list<SheetInspection>  $sheets
     * @param  int|null  $firstDataSheet  Index of the first visible sheet with data; when every sheet that has
     *                                    data is hidden, the first of those; null when no sheet has data.
     */
    public function __construct(
        public Format $format,
        public array $sheets,
        public ?int $firstDataSheet,
    ) {}
}
