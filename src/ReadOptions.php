<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

use InvalidArgumentException;

/**
 * Tuning and safety limits for readers.
 */
final readonly class ReadOptions
{
    /**
     * @param  string|null  $csvDelimiter  CSV field delimiter; null detects it from the file.
     * @param  string|null  $csvEncoding  CSV encoding; null detects it from the file.
     * @param  string  $csvEnclosure  CSV enclosure character.
     * @param  int  $sharedStringsInMemory  XLSX shared strings kept in memory before the rest spill to a temporary file.
     * @param  int  $maxZipEntries  Most entries an XLSX or ODS archive may hold.
     * @param  int  $maxUncompressedBytes  Most bytes one archive entry, and all entries together, may decompress to.
     * @param  int  $maxCompressionRatio  Largest compression ratio of an archive entry larger than 1 MiB.
     * @param  int  $maxCellBytes  Longest text of a single cell or CSV field, in bytes.
     * @param  int  $maxCells  Most cells an ODS sheet may produce by repeating cells and rows.
     * @param  int  $maxSharedStrings  Most entries an XLSX shared-string table may hold.
     *
     * @throws InvalidArgumentException when a limit is not a positive integer
     */
    public function __construct(
        public ?string $csvDelimiter = null,
        public ?string $csvEncoding = null,
        public string $csvEnclosure = '"',
        public int $sharedStringsInMemory = 100_000,
        public int $maxZipEntries = 5_000,
        public int $maxUncompressedBytes = 4_294_967_296,
        public int $maxCompressionRatio = 200,
        public int $maxCellBytes = 1_048_576,
        public int $maxCells = 100_000_000,
        public int $maxSharedStrings = 10_000_000,
    ) {
        if ($sharedStringsInMemory < 0) {
            throw new InvalidArgumentException('sharedStringsInMemory must not be negative.');
        }

        foreach ([
            'maxZipEntries' => $maxZipEntries,
            'maxUncompressedBytes' => $maxUncompressedBytes,
            'maxCompressionRatio' => $maxCompressionRatio,
            'maxCellBytes' => $maxCellBytes,
            'maxCells' => $maxCells,
            'maxSharedStrings' => $maxSharedStrings,
        ] as $name => $value) {
            if ($value < 1) {
                throw new InvalidArgumentException(sprintf('%s must be a positive integer, %d given.', $name, $value));
            }
        }
    }
}
