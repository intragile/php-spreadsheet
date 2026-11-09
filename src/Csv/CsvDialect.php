<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

/**
 * How a CSV file is encoded and delimited.
 */
final readonly class CsvDialect
{
    public function __construct(
        public string $delimiter,
        public string $enclosure,
        public string $encoding,
        public int $bomLength,
    ) {}
}
