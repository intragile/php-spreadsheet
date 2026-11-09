<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

/**
 * Tuning and safety limits for readers.
 */
final class ReadOptions
{
    public function __construct(
        public ?string $csvDelimiter = null,
        public ?string $csvEncoding = null,
        public string $csvEnclosure = '"',
        public int $sharedStringsInMemory = 100_000,
        public int $maxZipEntries = 5_000,
        public int $maxUncompressedBytes = 4_294_967_296,
        public int $maxCompressionRatio = 200,
    ) {}
}
