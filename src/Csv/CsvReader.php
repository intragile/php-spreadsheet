<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use Generator;
use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\HeaderKeyedRows;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use InvalidArgumentException;
use LogicException;
use Throwable;

/**
 * Streams a CSV file one record at a time. Every value is a string; nothing is type-inferred.
 *
 * Input in another encoding is decoded to UTF-8 losslessly: a byte the code page leaves undefined
 * becomes U+FFFD instead of failing the read. A field longer than ReadOptions::$maxCellBytes or a
 * record with more than 16384 fields throws LimitExceeded before it is buffered.
 */
final class CsvReader implements Reader
{
    use HeaderKeyedRows;

    private const SHEET_NAME = 'Sheet1';

    /** Most fields one record may hold: the column count of Excel and LibreOffice. */
    private const MAX_FIELDS = 16_384;

    private readonly int $maxCellBytes;

    private readonly CsvDialect $dialect;

    /** @var array<int, resource> */
    private array $handles = [];

    public function __construct(private readonly string $path, ReadOptions $options)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        $sniffed = (new CsvSniffer)->sniff($path);
        [$encoding, $bomLength] = self::resolveEncoding($options->csvEncoding, $sniffed);

        if ($options->csvDelimiter !== null && strlen($options->csvDelimiter) !== 1) {
            throw new InvalidArgumentException('The CSV delimiter must be a single byte.');
        }

        if (strlen($options->csvEnclosure) !== 1) {
            throw new InvalidArgumentException('The CSV enclosure must be a single byte.');
        }

        $this->maxCellBytes = $options->maxCellBytes;
        $this->dialect = new CsvDialect(
            $options->csvDelimiter ?? $sniffed->delimiter,
            $options->csvEnclosure,
            $encoding,
            $bomLength,
            $sniffed->carriageReturnLines,
        );
    }

    public function sheets(): array
    {
        return [new SheetInfo(self::SHEET_NAME, 0)];
    }

    public function rows(int|string $sheet = 0): Generator
    {
        if ($sheet !== 0 && $sheet !== self::SHEET_NAME) {
            throw new InvalidArgumentException('A CSV file has one sheet: use 0 or "'.self::SHEET_NAME.'".');
        }

        return $this->stream();
    }

    public function close(): void
    {
        foreach ($this->handles as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        $this->handles = [];
    }

    /**
     * @return Generator<int, list<string>>
     */
    private function stream(): Generator
    {
        $handle = $this->open();
        $id = (int) $handle;
        $this->handles[$id] = $handle;

        try {
            $number = 0;

            while (($record = fgetcsv($handle, null, $this->dialect->delimiter, $this->dialect->enclosure, '')) !== false) {
                $number++;

                if ($this->isEmpty($record)) {
                    continue;
                }

                /** @var list<string> $cells */
                $cells = array_map(static fn (?string $cell): string => $cell ?? '', $record);

                yield $number => $cells;

                if (! array_key_exists($id, $this->handles)) {
                    throw new LogicException('The reader was closed while its rows were being read.');
                }
            }
        } finally {
            unset($this->handles[$id]);

            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * @return resource
     */
    private function open()
    {
        $handle = fopen($this->path, 'rb');

        if ($handle === false) {
            throw new SpreadsheetException("Cannot read \"{$this->path}\".");
        }

        if ($this->dialect->bomLength > 0) {
            fseek($handle, $this->dialect->bomLength);
        }

        try {
            if (strcasecmp($this->dialect->encoding, 'UTF-8') !== 0) {
                // Decode on the fly so fgetcsv keeps streaming; fails early on an unknown encoding.
                CsvDecoder::plan($this->dialect->encoding);
                CsvDecoder::register();
                self::appendFilter($handle, CsvDecoder::FILTER, $this->dialect->encoding);
            }

            if ($this->dialect->carriageReturnLines) {
                CsvLineEndingFilter::register();
                self::appendFilter($handle, CsvLineEndingFilter::FILTER, []);
            }

            CsvGuardFilter::register();
            self::appendFilter($handle, CsvGuardFilter::FILTER, [
                'delimiter' => $this->dialect->delimiter,
                'enclosure' => $this->dialect->enclosure,
                'maxFieldBytes' => $this->maxCellBytes,
                'maxFields' => self::MAX_FIELDS,
            ]);
        } catch (Throwable $exception) {
            fclose($handle);

            throw $exception;
        }

        return $handle;
    }

    /**
     * Applies a named encoding to the byte-order mark found in the file: the mark is skipped when
     * it belongs to that encoding, and it decides the byte order when the name leaves it open
     * ("UTF-16", "UCS-2", "UTF-32", "UCS-4"). A mark of another encoding is left in the data.
     *
     * @return array{string, int} encoding and number of bytes to skip
     */
    private static function resolveEncoding(?string $named, CsvDialect $sniffed): array
    {
        if ($named === null) {
            return [$sniffed->encoding, $sniffed->bomLength];
        }

        if ($sniffed->bomLength > 0) {
            $canonical = CsvDecoder::canonical($named);

            if ($canonical === $sniffed->encoding
                || (($canonical === 'UTF-16' || $canonical === 'UTF-32') && str_starts_with($sniffed->encoding, $canonical))) {
                return [$sniffed->encoding, $sniffed->bomLength];
            }
        }

        return [$named, 0];
    }

    /**
     * @param  resource  $handle
     * @param  string|array<string, int|string>  $params
     */
    private static function appendFilter($handle, string $filter, string|array $params): void
    {
        if (stream_filter_append($handle, $filter, STREAM_FILTER_READ, $params) === false) {
            throw new SpreadsheetException(sprintf('Cannot attach the %s stream filter.', $filter));
        }
    }

    /**
     * @param  array<int, string|null>  $record
     */
    private function isEmpty(array $record): bool
    {
        foreach ($record as $cell) {
            if ($cell !== null && $cell !== '') {
                return false;
            }
        }

        return true;
    }
}
