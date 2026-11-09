<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

/**
 * Name and position of one sheet in a workbook.
 */
final readonly class SheetInfo
{
    public function __construct(
        public string $name,
        public int $index,
        public bool $visible = true,
    ) {}
}
