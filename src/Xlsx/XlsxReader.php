<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

use DateTimeImmutable;
use Generator;
use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\Date\IsoDate;
use IntrAgile\Spreadsheet\Internal\Date\SerialDate;
use IntrAgile\Spreadsheet\Internal\HeaderKeyedRows;
use IntrAgile\Spreadsheet\Internal\Ooxml\StyleSheet;
use IntrAgile\Spreadsheet\Internal\Ooxml\Workbook;
use IntrAgile\Spreadsheet\Internal\Xml\SafeXmlReader;
use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use InvalidArgumentException;
use LogicException;
use Throwable;
use XMLReader;

/**
 * Streams the rows of an Excel workbook (SpreadsheetML), holding one row at a time plus the
 * shared-string table, whose size in memory is capped by ReadOptions::$sharedStringsInMemory.
 *
 * Numbers become int when integral and exactly representable, float otherwise, and
 * DateTimeImmutable when their cell format is a date or time format. Strings, formula results and
 * error values (such as "#N/A") are returned as text, booleans as bool. Empty cells, including
 * cells whose value is an empty string, are null; missing cells between two values are padded
 * with null, trailing empty cells are left out.
 */
final class XlsxReader implements Reader
{
    use HeaderKeyedRows;

    /** Integers beyond ±2^53 lose precision as float, so they stay float to say so. */
    private const MAX_EXACT_INT = 9007199254740992;

    /** Last row of Excel and LibreOffice; a non-empty row beyond it is rejected. */
    private const MAX_ROWS = 1_048_576;

    /** Distinct date serials remembered per pass; real sheets repeat a limited set of days. */
    private const DATE_CACHE_SIZE = 4096;

    /** @var list<array{name: string, part: string|null, visible: bool}> */
    private array $sheets = [];

    private bool $date1904 = false;

    /** @var array<int, true> cell format (xf) indexes that display a date or time */
    private array $dateStyles = [];

    private ?string $sharedStringsPart = null;

    private ?SharedStringTable $sharedStrings = null;

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
            $workbook = Workbook::read($zip);
            $this->sheets = $workbook->sheets;
            $this->date1904 = $workbook->date1904;
            $this->sharedStringsPart = $workbook->sharedStringsPart;

            if ($workbook->stylesPart !== null) {
                $this->dateStyles = StyleSheet::dateStyles($zip, $workbook->stylesPart);
            }
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
        $selected = $this->sheets[$this->sheetIndex($sheet)];

        if ($selected['part'] === null) {
            throw new CorruptFile(sprintf('The part of sheet "%s" is missing from the workbook.', $selected['name']));
        }

        return $this->stream($selected['part']);
    }

    public function close(): void
    {
        foreach ($this->archives as $zip) {
            $zip->close();
        }

        $this->archives = [];
        $this->sharedStrings?->close();
        $this->sharedStrings = null;
    }

    /**
     * Each generator reads through its own archive handle, so generators can run side by side
     * and every pass gets a fresh byte budget.
     *
     * @return Generator<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private function stream(string $part): Generator
    {
        $zip = $this->open();
        $id = $this->nextArchive++;
        $this->archives[$id] = $zip;
        $xml = null;

        try {
            if (! $zip->has($part)) {
                throw new CorruptFile('The worksheet part "'.$part.'" is missing.');
            }

            $strings = $this->sharedStrings ??= $this->readSharedStrings($zip);
            $memory = $strings->inMemory();
            $dateStyles = $this->dateStyles;
            $checkStyles = $dateStyles !== [];
            $date1904 = $this->date1904;
            $maxCellBytes = $this->options->maxCellBytes;

            $xml = $zip->xmlReader($part);

            if (! self::enter($xml, 'sheetData')) {
                return;
            }

            // The hot loop talks to XMLReader directly; see SafeXmlReader::raw() for the contract.
            $x = $xml->raw();
            /** @var array<string, int> $columns column letters => index */
            $columns = [];
            /** @var array<string, DateTimeImmutable> $dates serial => date */
            $dates = [];
            $rowNumber = 0;
            $row = [];
            $count = 0;
            $position = 0;
            $complete = false;

            while ($x->read()) {
                $nodeType = $x->nodeType;

                if ($nodeType === XMLReader::ELEMENT) {
                    $name = $x->localName;

                    if ($name === 'c') {
                        $reference = $x->getAttribute('r');

                        if ($reference === null) {
                            $column = $position;

                            if ($column > ColumnName::MAX_INDEX) {
                                throw new LimitExceeded(sprintf('A row has more than %d columns.', ColumnName::MAX_INDEX + 1));
                            }
                        } else {
                            $letters = rtrim($reference, '0123456789');
                            $column = $columns[$letters] ?? ($columns[$letters] = self::columnIndex($letters, $reference));
                        }

                        $position = $column + 1;

                        if ($x->isEmptyElement) {
                            continue;
                        }

                        $type = $x->getAttribute('t');
                        $style = $checkStyles && ($type === null || $type === 'n') ? $x->getAttribute('s') : null;
                        $raw = null;
                        $inline = null;

                        // After a failed read() or next() XMLReader unwinds with synthetic end tags,
                        // so every step stops at the first false.
                        if (! $x->read()) {
                            break;
                        }

                        // Fast path for the usual <c><v>...</v></c>: next() lands on </c>.
                        if ($x->localName === 'v') {
                            $raw = $x->readString();

                            if (! $x->next()) {
                                break;
                            }
                        }

                        // Anything else, such as a formula (<f>) before the value or an inline
                        // string (<is>): walk the children up to </c>. Formula text is passed over.
                        // The reader is on a child of <c> here, or on </c> itself.
                        $depth = $x->nodeType === XMLReader::END_ELEMENT ? -1 : $x->depth - 1;

                        while ($depth >= 0 && ($x->nodeType !== XMLReader::END_ELEMENT || $x->depth !== $depth)) {
                            if ($x->nodeType === XMLReader::ELEMENT) {
                                $child = $x->localName;

                                if ($child === 'v') {
                                    $raw = $x->readString();

                                    if (! $x->next()) {
                                        break 2;
                                    }

                                    continue;
                                }

                                if ($child === 'is' && ! $x->isEmptyElement) {
                                    $itemDepth = $x->depth;

                                    if (! $x->read() || ($inline = self::richText($x, $itemDepth, $maxCellBytes)) === null) {
                                        break 2;
                                    }

                                    $inline = self::unescape($inline);
                                }
                            }

                            if (! $x->read()) {
                                break 2;
                            }
                        }

                        if ($type === null || $type === 'n') {
                            if ($raw === null || $raw === '') {
                                continue;
                            }

                            if (! is_numeric($raw)) {
                                if (strlen($raw) > $maxCellBytes) {
                                    throw self::cellTooLong($maxCellBytes);
                                }

                                $value = $raw;
                            } elseif ($style !== null && isset($dateStyles[(int) $style])
                                && (isset($dates[$raw]) || self::isCalendarSerial($raw))) {
                                // Serials outside the calendar skip this branch and stay numbers.
                                // Immutable, so one instance can serve every cell with the same serial.
                                if (! isset($dates[$raw])) {
                                    if (count($dates) >= self::DATE_CACHE_SIZE) {
                                        $dates = [];
                                    }

                                    $dates[$raw] = SerialDate::toDateTime((float) $raw, $date1904);
                                }

                                $value = $dates[$raw];
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
                        } elseif ($type === 's') {
                            if ($raw === null || $raw === '') {
                                continue;
                            }

                            $index = (int) $raw;
                            $value = $memory[$index] ?? $strings->get($index);

                            if ($value === '') {
                                continue;
                            }
                        } else {
                            $value = match ($type) {
                                'inlineStr' => $inline ?? $raw,
                                'b' => match ($raw) {
                                    '1', 'true' => true,
                                    '0', 'false' => false,
                                    default => $raw,
                                },
                                'd' => self::isoDate($raw),
                                // Formula string results carry _xHHHH_ escapes like any other text.
                                'str' => $raw === null ? null : self::unescape($raw),
                                default => $raw,
                            };

                            if ($value === null || $value === '') {
                                continue;
                            }

                            if (is_string($value) && strlen($value) > $maxCellBytes) {
                                throw self::cellTooLong($maxCellBytes);
                            }
                        }

                        if ($column === $count) {
                            $row[] = $value;
                            $count++;
                        } elseif ($column > $count) {
                            while ($count < $column) {
                                $row[] = null;
                                $count++;
                            }

                            $row[] = $value;
                            $count++;
                        } else {
                            $row[$column] = $value;
                        }
                    } elseif ($name === 'row') {
                        $number = (int) $x->getAttribute('r');
                        $rowNumber = $number > 0 ? $number : $rowNumber + 1;

                        // Rows past the last one are refused only if they hold a value, which is
                        // known at </row>; the number is pinned so it cannot keep growing.
                        if ($rowNumber > self::MAX_ROWS) {
                            $rowNumber = self::MAX_ROWS + 1;
                        }

                        $row = [];
                        $count = 0;
                        $position = 0;
                    }
                } elseif ($nodeType === XMLReader::END_ELEMENT) {
                    $name = $x->localName;

                    if ($name === 'row') {
                        if ($xml->collectErrors()) {
                            break;
                        }

                        if ($count > 0) {
                            if ($rowNumber > self::MAX_ROWS) {
                                throw new LimitExceeded(sprintf('A non-empty row lies beyond row %d.', self::MAX_ROWS));
                            }

                            // Cells only ever append or overwrite an existing position.
                            /** @var list<int|float|string|bool|DateTimeImmutable|null> $row */
                            yield $rowNumber => $row;

                            if (! array_key_exists($id, $this->archives)) {
                                throw new LogicException('The reader was closed while its rows were being read.');
                            }

                            $row = [];
                            $count = 0;
                        }
                    } elseif ($name === 'sheetData') {
                        $complete = ! $xml->collectErrors();

                        break;
                    }
                }
            }

            if (! $complete) {
                // read() names the cause when one is known (malformed XML, exhausted byte budget);
                // either way a sheet that stops early is truncated.
                $xml->read();

                throw new CorruptFile('The worksheet ends before </sheetData>.');
            }
        } finally {
            $xml?->close();

            if (array_key_exists($id, $this->archives)) {
                unset($this->archives[$id]);
                $zip->close();
            }
        }
    }

    private function readSharedStrings(SafeZip $zip): SharedStringTable
    {
        $table = new SharedStringTable($this->options->sharedStringsInMemory, $this->options->maxSharedStrings);
        $maxCellBytes = $this->options->maxCellBytes;

        if ($this->sharedStringsPart === null) {
            return $table;
        }

        $xml = $zip->xmlReader($this->sharedStringsPart);

        try {
            if (self::enter($xml, 'sst')) {
                $x = $xml->raw();
                $complete = false;
                $items = 0;

                while ($x->read()) {
                    $nodeType = $x->nodeType;

                    if ($nodeType === XMLReader::ELEMENT) {
                        if ($x->localName === 'si') {
                            if ($x->isEmptyElement) {
                                $table->add('');

                                continue;
                            }

                            $depth = $x->depth;

                            if (! $x->read()) {
                                break;
                            }

                            $text = '';

                            // Fast path for the usual <si><t>...</t></si>: next() lands on </si>.
                            if ($x->localName === 't') {
                                $text = $x->readString();

                                if (! $x->next()) {
                                    break;
                                }
                            }

                            if ($x->nodeType !== XMLReader::END_ELEMENT || $x->depth !== $depth) {
                                $rest = self::richText($x, $depth, $maxCellBytes);

                                if ($rest === null) {
                                    break;
                                }

                                $text .= $rest;
                            }

                            if (strlen($text) > $maxCellBytes) {
                                throw self::cellTooLong($maxCellBytes);
                            }

                            $table->add(self::unescape($text));

                            if ((++$items & 1023) === 0 && $xml->collectErrors()) {
                                break;
                            }
                        }
                    } elseif ($nodeType === XMLReader::END_ELEMENT && $x->localName === 'sst') {
                        $complete = ! $xml->collectErrors();

                        break;
                    }
                }

                if (! $complete) {
                    $xml->read();

                    throw new CorruptFile('The shared-string table ends before </sst>.');
                }
            }
        } catch (Throwable $exception) {
            $table->close();

            throw $exception;
        } finally {
            $xml->close();
        }

        return $table;
    }

    /**
     * Concatenates the <t> runs of a string item (<si> or <is>), leaving out phonetic guides
     * (<rPh>). Starts with the reader on the item's first child and stops on the item's end tag
     * at $depth; null when the reader failed on the way. Escapes are left for unescape().
     *
     * @throws LimitExceeded when the text grows beyond $maxBytes
     */
    private static function richText(XMLReader $x, int $depth, int $maxBytes): ?string
    {
        $text = '';
        $inText = false;

        while (true) {
            $nodeType = $x->nodeType;

            if ($nodeType === XMLReader::ELEMENT) {
                $name = $x->localName;

                if ($name === 't') {
                    $inText = ! $x->isEmptyElement;
                } elseif ($name === 'rPh' && ! $x->isEmptyElement) {
                    $phonetic = $x->depth;

                    do {
                        if (! $x->read()) {
                            return null;
                        }
                    } while ($x->nodeType !== XMLReader::END_ELEMENT || $x->depth !== $phonetic);
                }
            } elseif ($nodeType === XMLReader::END_ELEMENT) {
                if ($x->depth === $depth) {
                    return $text;
                }

                $inText = false;
            } elseif ($inText) {
                $text .= $x->value;

                if (strlen($text) > $maxBytes) {
                    throw self::cellTooLong($maxBytes);
                }
            }

            if (! $x->read()) {
                return null;
            }
        }
    }

    /**
     * Decodes the _xHHHH_ escapes OOXML uses for characters XML cannot carry, such as _x000D_.
     */
    private static function unescape(string $text): string
    {
        if (! str_contains($text, '_x')) {
            return $text;
        }

        return preg_replace_callback(
            '/_x([0-9A-Fa-f]{4})_/',
            static fn (array $match): string => mb_chr((int) hexdec($match[1]), 'UTF-8') ?: '',
            $text,
        ) ?? $text;
    }

    /**
     * Parses the ISO 8601 value of a t="d" cell; an empty value is no date.
     *
     * @throws CorruptFile when the value is not such a date
     */
    private static function isoDate(?string $raw): ?DateTimeImmutable
    {
        return $raw === null || $raw === '' ? null : IsoDate::parse($raw);
    }

    /**
     * Whether a numeric cell value is a serial a date can be made of (0 up to 9999-12-31).
     */
    private static function isCalendarSerial(string $raw): bool
    {
        $serial = (float) $raw;

        return $serial >= 0.0 && $serial <= SerialDate::MAX_SERIAL;
    }

    private static function cellTooLong(int $maxBytes): LimitExceeded
    {
        return new LimitExceeded(sprintf('A cell holds more than %d bytes of text.', $maxBytes));
    }

    private static function columnIndex(string $letters, string $reference): int
    {
        try {
            return ColumnName::toIndex($letters);
        } catch (InvalidArgumentException) {
            throw new CorruptFile(sprintf('The cell reference "%s" is not valid.', $reference));
        }
    }

    /**
     * Advances to the first element named $element; false when it is missing or empty.
     */
    private static function enter(SafeXmlReader $xml, string $element): bool
    {
        while ($xml->read()) {
            if ($xml->nodeType() === XMLReader::ELEMENT && $xml->localName() === $element) {
                return ! $xml->isEmptyElement();
            }
        }

        return false;
    }

    private function sheetIndex(int|string $sheet): int
    {
        if (is_int($sheet)) {
            if (isset($this->sheets[$sheet])) {
                return $sheet;
            }

            throw new InvalidArgumentException(sprintf('Sheet index %d does not exist; available sheets: %s.', $sheet, $this->sheetNames()));
        }

        foreach ($this->sheets as $index => $candidate) {
            if ($candidate['name'] === $sheet) {
                return $index;
            }
        }

        throw new InvalidArgumentException(sprintf('Sheet "%s" does not exist; available sheets: %s.', $sheet, $this->sheetNames()));
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
