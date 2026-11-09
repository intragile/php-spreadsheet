<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Ods;

use DateTimeImmutable;
use Generator;
use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\Date\IsoDate;
use IntrAgile\Spreadsheet\Internal\HeaderKeyedRows;
use IntrAgile\Spreadsheet\Internal\Xml\SafeXmlReader;
use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use InvalidArgumentException;
use LogicException;
use XMLReader;

/**
 * Streams the rows of an OpenDocument spreadsheet (content.xml), holding one row at a time.
 *
 * Values follow office:value-type: float, percentage and currency become int when integral and
 * exactly representable and float otherwise, date becomes DateTimeImmutable (UTC wall-clock),
 * boolean becomes bool, and strings are the text paragraphs joined with "\n" with text:s, text:tab
 * and text:line-break expanded. Times of day (value-type "time") have no date, so the ISO 8601
 * duration (such as "PT13H45M00S") is returned as a string. Empty cells, including cells whose
 * text is empty, are null; missing cells between two values are padded with null, trailing empty
 * cells are left out, and so are rows without any value.
 *
 * Repeated empty cells and rows are counted, never expanded: a sheet that LibreOffice ends with a
 * million empty rows of 16384 empty cells costs nothing.
 */
final class OdsReader implements Reader
{
    use HeaderKeyedRows;

    private const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';

    private const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';

    private const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';

    private const NS_STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';

    private const CONTENT = 'content.xml';

    /** Integers beyond ±2^53 lose precision as float, so they stay float to say so. */
    private const MAX_EXACT_INT = 9007199254740992;

    /** Distinct date values remembered per pass; real sheets repeat a limited set of days. */
    private const DATE_CACHE_SIZE = 4096;

    /** Most cells one row may hold; LibreOffice itself stops at 16384, so this is generous. */
    private const MAX_COLUMNS = 65_536;

    /** Last row of Excel and LibreOffice; a non-empty row beyond it is rejected. */
    private const MAX_ROWS = 1_048_576;

    /** Longest run of spaces a single text:s may expand to. */
    private const MAX_SPACES = 1_048_576;

    /** @var list<array{name: string, visible: bool}> */
    private array $sheets = [];

    /** Set when the sheet list could not be read to the end; reported when a sheet is missing. */
    private ?CorruptFile $scanFailure = null;

    private bool $spreadsheetSeen = false;

    /** @var array<int, SafeZip> archives opened by unfinished row generators */
    private array $archives = [];

    private int $nextArchive = 0;

    public function __construct(private readonly string $path, private readonly ReadOptions $options)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        $zip = $this->open();

        try {
            if (! $zip->has(self::CONTENT)) {
                throw new CorruptFile('The content.xml part is missing.');
            }

            $this->scanSheets($zip);
        } finally {
            $zip->close();
        }
    }

    public function sheets(): array
    {
        $sheets = [];

        foreach ($this->sheets as $index => $sheet) {
            $sheets[] = new SheetInfo($sheet['name'], $index, $sheet['visible']);
        }

        return $sheets;
    }

    public function rows(int|string $sheet = 0): Generator
    {
        return $this->stream($this->sheetIndex($sheet));
    }

    public function close(): void
    {
        foreach ($this->archives as $zip) {
            $zip->close();
        }

        $this->archives = [];
    }

    /**
     * Lists the tables with a first pass that skips their contents. A document that turns out
     * malformed part-way keeps the tables seen so far; the failure surfaces when a table beyond
     * them is asked for, or while a table that was cut short is being read.
     */
    private function scanSheets(SafeZip $zip): void
    {
        $xml = $zip->xmlReader(self::CONTENT);
        /** @var array<string, true> $hiddenStyles */
        $hiddenStyles = [];
        $style = null;
        $spreadsheetDepth = null;
        $hold = false;

        try {
            while ($hold || $xml->read()) {
                $hold = false;
                $nodeType = $xml->nodeType();

                if ($nodeType === XMLReader::END_ELEMENT) {
                    $name = $xml->localName();

                    if ($name === 'style') {
                        $style = null;
                    } elseif ($name === 'spreadsheet') {
                        return;
                    }

                    continue;
                }

                if ($nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->localName();

                if ($spreadsheetDepth === null) {
                    if ($name === 'style' && $xml->attribute('family', self::NS_STYLE) === 'table') {
                        $style = $xml->isEmptyElement() ? null : $xml->attribute('name', self::NS_STYLE);
                    } elseif ($name === 'table-properties' && $style !== null && $xml->attribute('display', self::NS_TABLE) === 'false') {
                        $hiddenStyles[$style] = true;
                    } elseif ($name === 'spreadsheet') {
                        if ($xml->isEmptyElement()) {
                            return;
                        }

                        $spreadsheetDepth = $xml->depth();
                        $this->spreadsheetSeen = true;
                    }
                } elseif ($name === 'table' && $xml->depth() === $spreadsheetDepth + 1) {
                    $styleName = $xml->attribute('style-name', self::NS_TABLE);
                    $this->sheets[] = [
                        'name' => (string) $xml->attribute('name', self::NS_TABLE),
                        'visible' => $styleName === null || ! isset($hiddenStyles[$styleName]),
                    ];

                    // next() lands on the following sibling, which must not be skipped by read().
                    $hold = true;

                    if (! $xml->next()) {
                        break;
                    }
                }
            }

            if (! $this->spreadsheetSeen) {
                throw new CorruptFile('The content.xml part has no spreadsheet.');
            }
        } catch (LimitExceeded $exception) {
            throw $exception;
        } catch (CorruptFile $exception) {
            if (! $this->spreadsheetSeen) {
                throw $exception;
            }

            $this->scanFailure = $exception;
        } finally {
            $xml->close();
        }
    }

    /**
     * Each generator reads through its own archive handle, so generators can run side by side
     * and every pass gets a fresh byte budget.
     *
     * @return Generator<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private function stream(int $sheet): Generator
    {
        $zip = $this->open();
        $id = $this->nextArchive++;
        $this->archives[$id] = $zip;
        $xml = null;

        try {
            $xml = $zip->xmlReader(self::CONTENT);

            if (! self::locateTable($xml, $sheet)) {
                throw new CorruptFile(sprintf('The table "%s" is missing from content.xml.', $this->sheets[$sheet]['name']));
            }

            if ($xml->isEmptyElement()) {
                return;
            }

            // The hot loop talks to XMLReader directly; see SafeXmlReader::raw() for the contract.
            $x = $xml->raw();
            $tableDepth = $x->depth;
            /** @var array<string, DateTimeImmutable> $dates */
            $dates = [];
            $row = [];
            $count = 0;
            $pending = 0;
            $rowNumber = 0;
            $firstRow = 1;
            $rowRepeat = 1;
            $complete = false;
            $hold = false;

            while ($hold || $x->read()) {
                $hold = false;
                $nodeType = $x->nodeType;

                if ($nodeType === XMLReader::ELEMENT) {
                    $name = $x->localName;

                    if ($name === 'table-cell' || $name === 'covered-table-cell') {
                        $repeat = self::repeat($x->getAttributeNs('number-columns-repeated', self::NS_TABLE));
                        $type = $x->getAttributeNs('value-type', self::NS_OFFICE);
                        $empty = $x->isEmptyElement;
                        $value = null;
                        $text = false;

                        if ($type === 'float' || $type === 'percentage' || $type === 'currency') {
                            $raw = $x->getAttributeNs('value', self::NS_OFFICE);

                            if ($raw === null || $raw === '') {
                                $value = null;
                            } elseif (! is_numeric($raw)) {
                                $value = $raw;
                            } else {
                                // Numeric strings add up to int or float exactly as PHP parses them.
                                $value = $raw + 0;

                                if (is_int($value)) {
                                    if ($value >= self::MAX_EXACT_INT || $value <= -self::MAX_EXACT_INT) {
                                        $value = (float) $value;
                                    }
                                } elseif ($value === floor($value) && abs($value) < self::MAX_EXACT_INT) {
                                    $value = (int) $value;
                                }
                            }
                        } elseif ($type === 'string') {
                            $value = $x->getAttributeNs('string-value', self::NS_OFFICE);
                            $text = $value === null && ! $empty;
                        } elseif ($type === 'date') {
                            $raw = $x->getAttributeNs('date-value', self::NS_OFFICE);

                            if ($raw !== null && $raw !== '') {
                                if (! isset($dates[$raw])) {
                                    if (count($dates) >= self::DATE_CACHE_SIZE) {
                                        $dates = [];
                                    }

                                    $dates[$raw] = IsoDate::parse($raw);
                                }

                                // Immutable, so one instance can serve every cell with the same value.
                                $value = $dates[$raw];
                            }
                        } elseif ($type === 'boolean') {
                            $raw = $x->getAttributeNs('boolean-value', self::NS_OFFICE);
                            $value = match ($raw) {
                                'true', '1' => true,
                                'false', '0' => false,
                                '' => null,
                                default => $raw,
                            };
                        } elseif ($type === 'time') {
                            $value = $x->getAttributeNs('time-value', self::NS_OFFICE);
                        } elseif ($type !== null && $type !== 'void') {
                            // An unknown type still carries its text.
                            $value = $x->getAttributeNs('string-value', self::NS_OFFICE);
                            $text = $value === null && ! $empty;
                        }

                        // After a failed read() or next() XMLReader unwinds with synthetic end tags,
                        // so every step stops at the first false.
                        if ($text) {
                            $value = self::cellText($x);

                            if ($value === null) {
                                break;
                            }
                        } elseif (! $empty) {
                            // Skips the content (text copy of the value, annotations); lands on the next sibling.
                            $hold = true;

                            if (! $x->next()) {
                                break;
                            }
                        }

                        if ($value === null || $value === '') {
                            $pending += $repeat;

                            continue;
                        }

                        if ($count + $pending + $repeat > self::MAX_COLUMNS) {
                            throw new LimitExceeded(sprintf('A row has more than %d cells.', self::MAX_COLUMNS));
                        }

                        for (; $pending > 0; $pending--) {
                            $row[] = null;
                            $count++;
                        }

                        for ($i = 0; $i < $repeat; $i++) {
                            $row[] = $value;
                        }

                        $count += $repeat;
                    } elseif ($name === 'table-row') {
                        $rowRepeat = self::repeat($x->getAttributeNs('number-rows-repeated', self::NS_TABLE));
                        $firstRow = $rowNumber + 1;
                        $row = [];
                        $count = 0;
                        $pending = 0;

                        if ($x->isEmptyElement) {
                            $rowNumber += $rowRepeat;
                        }
                    }
                } elseif ($nodeType === XMLReader::END_ELEMENT) {
                    $name = $x->localName;

                    if ($name === 'table-row') {
                        if ($xml->collectErrors()) {
                            break;
                        }

                        if ($count > 0) {
                            if ($firstRow + $rowRepeat - 1 > self::MAX_ROWS) {
                                throw new LimitExceeded(sprintf('A non-empty row lies beyond row %d.', self::MAX_ROWS));
                            }

                            /** @var list<int|float|string|bool|DateTimeImmutable|null> $row */
                            for ($i = 0; $i < $rowRepeat; $i++) {
                                yield $firstRow + $i => $row;

                                if (! array_key_exists($id, $this->archives)) {
                                    throw new LogicException('The reader was closed while its rows were being read.');
                                }
                            }
                        }

                        $rowNumber += $rowRepeat;
                        $row = [];
                        $count = 0;
                        $pending = 0;
                    } elseif ($name === 'table' && $x->depth === $tableDepth) {
                        $complete = ! $xml->collectErrors();

                        break;
                    }
                }
            }

            if (! $complete) {
                // read() names the cause when one is known (malformed XML, exhausted byte budget);
                // either way a table that stops early is truncated.
                $xml->read();

                throw new CorruptFile('The table ends before </table:table>.');
            }
        } finally {
            $xml?->close();

            if (array_key_exists($id, $this->archives)) {
                unset($this->archives[$id]);
                $zip->close();
            }
        }
    }

    /**
     * Positions the reader on the start tag of the $index-th table of the spreadsheet.
     */
    private static function locateTable(SafeXmlReader $xml, int $index): bool
    {
        $spreadsheetDepth = null;
        $seen = 0;
        $hold = false;

        while ($hold || $xml->read()) {
            $hold = false;

            if ($xml->nodeType() !== XMLReader::ELEMENT) {
                continue;
            }

            $name = $xml->localName();

            if ($spreadsheetDepth === null) {
                if ($name === 'spreadsheet') {
                    if ($xml->isEmptyElement()) {
                        return false;
                    }

                    $spreadsheetDepth = $xml->depth();
                }
            } elseif ($name === 'table' && $xml->depth() === $spreadsheetDepth + 1) {
                if ($seen++ === $index) {
                    return true;
                }

                // next() lands on the following sibling, which must not be skipped by read().
                $hold = true;

                if (! $xml->next()) {
                    return false;
                }
            }
        }

        return false;
    }

    /**
     * Reads the text of a string cell: the paragraphs joined with "\n", with spaces, tabs and line
     * breaks expanded and annotations left out. Starts on the cell's start tag and stops on its
     * end tag; null when the reader failed on the way, an empty string when the cell has no text.
     */
    private static function cellText(XMLReader $x): ?string
    {
        $depth = $x->depth;
        $text = '';
        $paragraphs = 0;
        $paragraphDepth = -1;
        $hold = false;

        while ($hold || $x->read()) {
            $hold = false;
            $nodeType = $x->nodeType;

            if ($nodeType === XMLReader::ELEMENT) {
                $name = $x->localName;

                if ($name === 'p' || $name === 'h') {
                    if ($paragraphDepth < 0) {
                        if ($paragraphs++ > 0) {
                            $text .= "\n";
                        }

                        if (! $x->isEmptyElement) {
                            $paragraphDepth = $x->depth;
                        }
                    }
                } elseif ($name === 's') {
                    $spaces = $x->getAttributeNs('c', self::NS_TEXT);
                    $spaces = $spaces === null ? 1 : self::repeat($spaces, 'text:c');

                    if ($spaces > self::MAX_SPACES) {
                        throw new LimitExceeded('A text:s element expands to too many spaces.');
                    }

                    $text .= str_repeat(' ', $spaces);
                } elseif ($name === 'tab') {
                    $text .= "\t";
                } elseif ($name === 'line-break') {
                    $text .= "\n";
                } elseif (($name === 'annotation' || $name === 'table' || $name === 'shapes') && ! $x->isEmptyElement) {
                    // Notes are not cell content, and a nested table has rows of its own.
                    $hold = true;

                    if (! $x->next()) {
                        return null;
                    }
                }
            } elseif ($nodeType === XMLReader::END_ELEMENT) {
                if ($x->depth === $depth) {
                    return $text;
                }

                if ($x->depth === $paragraphDepth) {
                    $paragraphDepth = -1;
                }
            } elseif ($paragraphDepth >= 0 && ($nodeType === XMLReader::TEXT || $nodeType === XMLReader::SIGNIFICANT_WHITESPACE || $nodeType === XMLReader::CDATA)) {
                $text .= $x->value;
            }
        }

        return null;
    }

    /**
     * A repeat count: a positive decimal integer, 1 when absent.
     *
     * @throws CorruptFile when the attribute is not a positive integer
     */
    private static function repeat(?string $value, string $attribute = 'repeat'): int
    {
        if ($value === null) {
            return 1;
        }

        if ($value === '' || strlen($value) > 12 || ! ctype_digit($value) || (int) $value < 1) {
            throw new CorruptFile(sprintf('The %s count "%s" is not a positive integer.', $attribute, $value));
        }

        return (int) $value;
    }

    private function sheetIndex(int|string $sheet): int
    {
        if (is_int($sheet)) {
            if (isset($this->sheets[$sheet])) {
                return $sheet;
            }

            $this->failMissingSheet();

            throw new InvalidArgumentException(sprintf('Sheet index %d does not exist; available sheets: %s.', $sheet, $this->sheetNames()));
        }

        foreach ($this->sheets as $index => $candidate) {
            if ($candidate['name'] === $sheet) {
                return $index;
            }
        }

        $this->failMissingSheet();

        throw new InvalidArgumentException(sprintf('Sheet "%s" does not exist; available sheets: %s.', $sheet, $this->sheetNames()));
    }

    /**
     * A sheet that cannot be found may lie beyond the point where the file broke off.
     */
    private function failMissingSheet(): void
    {
        if ($this->scanFailure !== null) {
            throw new CorruptFile('The sheet list ends early: '.$this->scanFailure->getMessage(), 0, $this->scanFailure);
        }
    }

    private function sheetNames(): string
    {
        if ($this->sheets === []) {
            return 'none';
        }

        return implode(', ', array_map(static fn (array $sheet): string => '"'.$sheet['name'].'"', $this->sheets));
    }

    private function open(): SafeZip
    {
        return SafeZip::open($this->path, $this->options->maxZipEntries, $this->options->maxUncompressedBytes, $this->options->maxCompressionRatio);
    }
}
