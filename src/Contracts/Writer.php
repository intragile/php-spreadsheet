<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Contracts;

use DateTimeInterface;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Style;

interface Writer
{
    /**
     * Starts a new sheet; the first addRow() without a sheet() call opens "Sheet1".
     */
    public function sheet(string $name, ?SheetOptions $options = null): static;

    /**
     * @param  iterable<int|float|string|bool|DateTimeInterface|Cell|null>  $values
     */
    public function addRow(iterable $values, ?Style $rowStyle = null): static;

    /**
     * @param  iterable<iterable<int|float|string|bool|DateTimeInterface|Cell|null>>  $rows
     */
    public function addRows(iterable $rows, ?Style $rowStyle = null): static;

    /**
     * Writes the final file atomically (temporary file, then rename), replacing an existing file
     * at the target path. Idempotent after a successful close; throws LogicException when an
     * earlier error aborted the write, as no file was written then.
     */
    public function close(): void;
}
