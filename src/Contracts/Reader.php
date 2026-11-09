<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Contracts;

use DateTimeImmutable;
use Generator;
use IntrAgile\Spreadsheet\SheetInfo;

interface Reader
{
    /**
     * @return list<SheetInfo>
     */
    public function sheets(): array;

    /**
     * Keys are 1-based row numbers; fully empty rows are skipped.
     *
     * @return Generator<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    public function rows(int|string $sheet = 0): Generator;

    /**
     * Header cells are trimmed; duplicate or blank headers become "name_2" / "column_N".
     *
     * @return Generator<int, array<string, int|float|string|bool|DateTimeImmutable|null>>
     */
    public function rowsWithHeader(int|string $sheet = 0, int $headerRow = 1): Generator;

    public function close(): void;
}
