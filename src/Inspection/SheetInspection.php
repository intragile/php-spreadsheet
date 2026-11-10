<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Inspection;

use DateTimeImmutable;
use IntrAgile\Spreadsheet\SheetInfo;

/**
 * What a bounded scan found out about one sheet.
 */
final readonly class SheetInspection
{
    /**
     * @param  int|null  $headerRow  Real 1-based row number of the detected header, or null.
     * @param  int  $columnCount  Width of the widest scanned row, trailing blank cells excluded.
     * @param  bool  $hasData  Whether any scanned row holds a non-blank cell.
     * @param  list<list<int|float|string|bool|DateTimeImmutable|null>>  $sampleRows  The scanned rows (empty rows are skipped).
     */
    public function __construct(
        public SheetInfo $info,
        public ?int $headerRow,
        public int $columnCount,
        public bool $hasData,
        public array $sampleRows,
    ) {}
}
