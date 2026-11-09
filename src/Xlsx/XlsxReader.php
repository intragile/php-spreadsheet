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

    private const RELATIONSHIP_NAMESPACES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    /** Integers beyond ±2^53 lose precision as float, so they stay float to say so. */
    private const MAX_EXACT_INT = 9007199254740992;

    /** Last row of Excel and LibreOffice; a row number beyond it is rejected. */
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
            $workbook = self::mainPart($zip) ?? throw new CorruptFile('The workbook part is missing.');
            $relationships = self::relationships($zip, self::relationshipsPath($workbook));
            $this->readWorkbook($zip, $workbook, $relationships);

            $sharedStrings = self::partOfType($zip, $relationships, 'sharedStrings') ?? self::sibling($workbook, 'sharedStrings.xml');
            $this->sharedStringsPart = $zip->has($sharedStrings) ? $sharedStrings : null;

            $styles = self::partOfType($zip, $relationships, 'styles') ?? self::sibling($workbook, 'styles.xml');

            if ($zip->has($styles)) {
                $this->readStyles($zip, $styles);
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

                                    if (! $x->read() || ($inline = self::richText($x, $itemDepth)) === null) {
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
                                $value = $raw;
                            } elseif ($style !== null && isset($dateStyles[(int) $style])) {
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
                                default => $raw,
                            };

                            if ($value === null || $value === '') {
                                continue;
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

                        if ($rowNumber > self::MAX_ROWS) {
                            throw new LimitExceeded(sprintf('The sheet has a row beyond row %d.', self::MAX_ROWS));
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
        $table = new SharedStringTable($this->options->sharedStringsInMemory);

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
                                $rest = self::richText($x, $depth);

                                if ($rest === null) {
                                    break;
                                }

                                $text .= $rest;
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
     */
    private static function richText(XMLReader $x, int $depth): ?string
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

    /**
     * @param  list<array{id: string, type: string, target: string}>  $relationships
     */
    private function readWorkbook(SafeZip $zip, string $workbook, array $relationships): void
    {
        $targets = [];

        foreach ($relationships as $relationship) {
            $targets[$relationship['id']] = $relationship['target'];
        }

        $xml = $zip->xmlReader($workbook);

        try {
            while ($xml->read()) {
                if ($xml->nodeType() !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->localName();

                if ($name === 'workbookPr') {
                    $this->date1904 = in_array(strtolower(trim((string) $xml->attribute('date1904'))), ['1', 'true'], true);
                } elseif ($name === 'sheet') {
                    $id = null;

                    foreach (self::RELATIONSHIP_NAMESPACES as $namespace) {
                        $id ??= $xml->attribute('id', $namespace);
                    }

                    $state = $xml->attribute('state');

                    $part = $id !== null ? ($targets[$id] ?? null) : null;

                    $this->sheets[] = [
                        'name' => (string) $xml->attribute('name'),
                        'part' => $part !== null && $zip->has($part) ? $part : null,
                        'visible' => $state !== 'hidden' && $state !== 'veryHidden',
                    ];
                }
            }
        } finally {
            $xml->close();
        }
    }

    /**
     * Builds the xf index => date map from the number formats and cell formats (cellXfs) of the
     * styles part. Conditional formats (dxfs) carry their own numFmt elements, which are ignored.
     */
    private function readStyles(SafeZip $zip, string $part): void
    {
        $xml = $zip->xmlReader($part);
        /** @var array<int, bool> $custom numFmtId => is a date */
        $custom = [];
        /** @var list<int> $cellFormats numFmtId of each xf */
        $cellFormats = [];
        $numFmtsDepth = null;
        $cellXfsDepth = null;

        try {
            while ($xml->read()) {
                $nodeType = $xml->nodeType();

                if ($nodeType === XMLReader::END_ELEMENT) {
                    if ($xml->depth() === $numFmtsDepth) {
                        $numFmtsDepth = null;
                    } elseif ($xml->depth() === $cellXfsDepth) {
                        $cellXfsDepth = null;
                    }

                    continue;
                }

                if ($nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->localName();

                if ($name === 'numFmts') {
                    $numFmtsDepth = $xml->isEmptyElement() ? null : $xml->depth();
                } elseif ($name === 'numFmt' && $numFmtsDepth !== null && $xml->depth() === $numFmtsDepth + 1) {
                    $id = self::unsignedInt($xml->attribute('numFmtId'));

                    if ($id !== null) {
                        $custom[$id] = NumberFormats::isDate((string) $xml->attribute('formatCode'));
                    }
                } elseif ($name === 'cellXfs') {
                    $cellXfsDepth = $xml->isEmptyElement() ? null : $xml->depth();
                } elseif ($name === 'xf' && $cellXfsDepth !== null && $xml->depth() === $cellXfsDepth + 1) {
                    // Every xf keeps its position, as cells refer to it by index; a missing id means General.
                    $cellFormats[] = self::unsignedInt($xml->attribute('numFmtId')) ?? 0;
                }
            }
        } finally {
            $xml->close();
        }

        foreach ($cellFormats as $index => $numFmtId) {
            if ($custom[$numFmtId] ?? NumberFormats::isBuiltInDate($numFmtId)) {
                $this->dateStyles[$index] = true;
            }
        }
    }

    private function open(): SafeZip
    {
        return SafeZip::open($this->path, $this->options->maxZipEntries, $this->options->maxUncompressedBytes, $this->options->maxCompressionRatio);
    }

    /**
     * The package's main part, through _rels/.rels, falling back to the conventional path.
     */
    private static function mainPart(SafeZip $zip): ?string
    {
        return self::partOfType($zip, self::relationships($zip, '_rels/.rels'), 'officeDocument')
            ?? ($zip->has('xl/workbook.xml') ? 'xl/workbook.xml' : null);
    }

    /**
     * The first existing target of a relationship type. Types are matched on their last path
     * segment, so both transitional and strict OOXML relationship URIs are recognised.
     *
     * @param  list<array{id: string, type: string, target: string}>  $relationships
     */
    private static function partOfType(SafeZip $zip, array $relationships, string $type): ?string
    {
        foreach ($relationships as $relationship) {
            if (str_ends_with($relationship['type'], '/'.$type) && $zip->has($relationship['target'])) {
                return $relationship['target'];
            }
        }

        return null;
    }

    /**
     * Internal relationships in document order, with targets resolved to package paths.
     *
     * @return list<array{id: string, type: string, target: string}>
     */
    private static function relationships(SafeZip $zip, string $relsPath): array
    {
        if (! $zip->has($relsPath)) {
            return [];
        }

        $directory = dirname($relsPath, 2);
        $base = $directory === '.' ? '' : $directory.'/';
        $xml = $zip->xmlReader($relsPath);
        $entries = [];

        try {
            while ($xml->read()) {
                if ($xml->nodeType() !== XMLReader::ELEMENT || $xml->localName() !== 'Relationship') {
                    continue;
                }

                $id = $xml->attribute('Id');
                $target = $xml->attribute('Target');

                if ($id === null || $target === null || strcasecmp((string) $xml->attribute('TargetMode'), 'External') === 0) {
                    continue;
                }

                $resolved = self::resolve($base, rawurldecode($target));

                if ($resolved !== null) {
                    $entries[] = ['id' => $id, 'type' => (string) $xml->attribute('Type'), 'target' => $resolved];
                }
            }
        } finally {
            $xml->close();
        }

        return $entries;
    }

    private static function resolve(string $base, string $target): ?string
    {
        $path = str_starts_with($target, '/') ? substr($target, 1) : $base.$target;
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * "xl/workbook.xml" keeps its relationships in "xl/_rels/workbook.xml.rels".
     */
    private static function relationshipsPath(string $part): string
    {
        $slash = strrpos($part, '/');

        return $slash === false
            ? '_rels/'.$part.'.rels'
            : substr($part, 0, $slash).'/_rels/'.substr($part, $slash + 1).'.rels';
    }

    private static function sibling(string $part, string $name): string
    {
        $slash = strrpos($part, '/');

        return $slash === false ? $name : substr($part, 0, $slash + 1).$name;
    }

    /**
     * A plain non-negative decimal integer within 32 bits, or null.
     */
    private static function unsignedInt(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || strlen($value) > 10 || ! ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number <= 0xFFFFFFFF ? $number : null;
    }
}
