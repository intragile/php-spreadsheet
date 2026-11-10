<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Inspection;

/**
 * Size of a sheet measured by a bounded scan.
 *
 * When the scan stopped early, the figures cover only the part that was read.
 */
final readonly class CellCount
{
    /**
     * @param  int  $rows  Number (1-based) of the last non-empty row that was read.
     * @param  int  $columns  Width of the widest row read, trailing blank cells excluded.
     * @param  int  $cells  Non-empty cells counted.
     * @param  bool  $exceeded  Whether the cell count went above the limit and the scan stopped.
     */
    public function __construct(
        public int $rows,
        public int $columns,
        public int $cells,
        public bool $exceeded,
    ) {}
}
