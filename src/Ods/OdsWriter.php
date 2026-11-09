<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Ods;

use DateTimeInterface;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Contracts\Writer;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\SheetName;
use IntrAgile\Spreadsheet\Internal\TempFile;
use IntrAgile\Spreadsheet\Internal\Xml\XmlEscaper;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Style;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Streams rows into an OpenDocument spreadsheet (ODS) in constant memory.
 *
 * Each sheet's rows go to their own temporary file as they arrive; only the current sheet's file
 * is open. content.xml is assembled on close by stream-copying those files between the header
 * (which needs every style and column layout, so it cannot be written earlier) and the footer,
 * and the package is built next to the target and renamed into place, so the target appears
 * complete or not at all.
 *
 * A cell's own style (Cell::text(), Cell::formula(), Cell::of()) replaces the row style for that
 * cell; the two are not merged.
 *
 * Formulas are written as OpenFormula ("of:="). Plain A1 references and ranges and the comma
 * argument separator are converted; a formula with sheet references, quoted names or structured
 * references is written unchanged and may need editing in the office application. No cached
 * result is stored, so readers see an empty cell until the application recalculates.
 */
final class OdsWriter implements Writer
{
    public const MAX_ROWS = 1_048_576;

    private const MAX_COLUMNS = 16_384;

    private const BUFFER_BYTES = 65_536;

    /** Deflate level of content.xml; see XlsxWriter for the trade-off. */
    private const COMPRESSION_LEVEL = 1;

    private const MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';

    private const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8"?>'."\n";

    /**
     * Text that needs escaping, line splitting or explicit spaces; anything else, if valid UTF-8,
     * is written as is. A space at either end or doubled up would be collapsed by readers.
     */
    private const NEEDS_ENCODING = '/[\x00-\x1F&<>\x{FFFE}\x{FFFF}]|  |^ | $/u';

    private const DOCUMENT_NAMESPACES = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        .'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
        .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
        .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        .'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
        .'xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" '
        .'xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" '
        .'xmlns:config="urn:oasis:names:tc:opendocument:xmlns:config:1.0"';

    private readonly OdsStyleRegistry $styles;

    /** @var resource|null */
    private $handle = null;

    /** @var list<string> temporary file of every sheet, in workbook order */
    private array $sheetPaths = [];

    /** @var list<array{name: string, options: ?SheetOptions, rows: int, columns: int, firstRowWidth: int}> */
    private array $sheets = [];

    /** @var array<string, true> lowercased sheet names */
    private array $sheetNames = [];

    private ?string $contentPath = null;

    private ?string $zipPath = null;

    private string $buffer = '';

    private int $rowNumber = 0;

    private int $columns = 0;

    private int $firstRowWidth = 0;

    private bool $closed = false;

    public function __construct(private readonly string $path)
    {
        $this->styles = new OdsStyleRegistry;
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->abort();
        }
    }

    public function sheet(string $name, ?SheetOptions $options = null): static
    {
        $this->assertOpen();

        // Validation leaves the writer untouched, so a rejected sheet can be retried.
        SheetName::validate($name, $this->sheetNames);
        self::validateOptions($options);

        try {
            $this->finishSheet();
            $this->openSheet($name, $options);
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }

        return $this;
    }

    public function addRow(iterable $values, ?Style $rowStyle = null): static
    {
        $this->assertOpen();

        try {
            if ($this->handle === null) {
                $this->openSheet('Sheet1', null);
            }

            $r = ++$this->rowNumber;

            if ($r > self::MAX_ROWS) {
                throw new SpreadsheetException(sprintf('A sheet holds at most %d rows.', self::MAX_ROWS));
            }

            $rowAttribute = $this->styleAttribute($rowStyle);
            $xml = '<table:table-row>';
            $emitted = false;
            // Empty unstyled cells are counted and written as one repeated cell before the next
            // value; trailing ones are dropped.
            $gap = 0;
            $column = 0;

            foreach ($values as $value) {
                $column++;
                $style = $rowStyle;
                $attribute = $rowAttribute;

                if ($value instanceof Cell) {
                    if ($value->style !== null) {
                        $style = $value->style;
                        $attribute = $this->styleAttribute($style);
                    }

                    if ($value->kind === Cell::KIND_FORMULA) {
                        $xml .= self::gap($gap).'<table:table-cell'.$attribute.' table:formula="of:='
                            .self::formula(is_string($value->value) ? substr($value->value, 1) : '').'"/>';
                        $gap = 0;
                        $emitted = true;

                        continue;
                    }

                    $value = $value->value;
                }

                if ($value === null || $value === '') {
                    // Empty, but a style still applies so fills run across blank columns.
                    if ($attribute === '') {
                        $gap++;
                    } else {
                        $xml .= self::gap($gap).'<table:table-cell'.$attribute.'/>';
                        $gap = 0;
                        $emitted = true;
                    }

                    continue;
                }

                $xml .= self::gap($gap);
                $gap = 0;
                $emitted = true;

                if (is_string($value)) {
                    $xml .= '<table:table-cell'.$attribute.' office:value-type="string">'.self::paragraphs($value).'</table:table-cell>';
                } elseif (is_int($value)) {
                    $xml .= '<table:table-cell'.$attribute.' office:value-type="float" office:value="'.$value.'"/>';
                } elseif (is_float($value)) {
                    $xml .= '<table:table-cell'.$attribute.' office:value-type="float" office:value="'.self::number($value).'"/>';
                } elseif (is_bool($value)) {
                    $xml .= '<table:table-cell'.$attribute.' office:value-type="boolean" office:boolean-value="'.($value ? 'true' : 'false').'"/>';
                } elseif ($value instanceof DateTimeInterface) {
                    $xml .= $this->date($value, $style);
                } else {
                    throw new InvalidArgumentException(sprintf('Unsupported cell value of type %s in column %d of row %d.', get_debug_type($value), $column, $r));
                }
            }

            if ($column > self::MAX_COLUMNS) {
                throw new SpreadsheetException(sprintf('Row %d has %d cells; a sheet holds at most %d columns.', $r, $column, self::MAX_COLUMNS));
            }

            $used = $column - $gap;
            $this->columns = max($this->columns, $used);

            if ($r === 1) {
                $this->firstRowWidth = $used;
            }

            // A row element needs at least one cell.
            $this->buffer .= $xml.($emitted ? '' : '<table:table-cell/>').'</table:table-row>';

            if (strlen($this->buffer) >= self::BUFFER_BYTES) {
                $this->flush();
            }
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }

        return $this;
    }

    public function addRows(iterable $rows, ?Style $rowStyle = null): static
    {
        $this->assertOpen();

        try {
            foreach ($rows as $row) {
                $this->addRow($row, $rowStyle);
            }
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }

        return $this;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            if ($this->sheets === []) {
                $this->openSheet('Sheet1', null);
            }

            $this->finishSheet();
            $this->writeContent();
            $this->writeArchive();
            $this->closed = true;
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    private function openSheet(string $name, ?SheetOptions $options): void
    {
        $path = TempFile::create('sps_ods_', dirname($this->path));
        $this->sheetPaths[] = $path;
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open '.$path.' for writing.');
        }

        $this->handle = $handle;
        $this->sheets[] = ['name' => $name, 'options' => $options, 'rows' => 0, 'columns' => 0, 'firstRowWidth' => 0];
        $this->sheetNames[mb_strtolower($name, 'UTF-8')] = true;
        $this->rowNumber = 0;
        $this->columns = 0;
        $this->firstRowWidth = 0;
        $this->buffer = '';
    }

    /**
     * Closes the current sheet's file and records its extent; the next sheet starts with a fresh handle.
     */
    private function finishSheet(): void
    {
        $handle = $this->handle;

        if ($handle === null) {
            return;
        }

        $this->flush();
        $this->handle = null;
        $last = count($this->sheets) - 1;
        $sheet = $this->sheets[$last];
        $sheet['rows'] = $this->rowNumber;
        $sheet['columns'] = $this->columns;
        $sheet['firstRowWidth'] = $this->firstRowWidth;
        $this->sheets[$last] = $sheet;

        if (! fclose($handle)) {
            throw new RuntimeException('Cannot finish writing '.end($this->sheetPaths).'.');
        }
    }

    private function flush(): void
    {
        if ($this->handle === null || $this->buffer === '') {
            return;
        }

        if (fwrite($this->handle, $this->buffer) !== strlen($this->buffer)) {
            throw new RuntimeException('Cannot write to '.end($this->sheetPaths).'.');
        }

        $this->buffer = '';
    }

    /**
     * Assembles content.xml: header and styles, every sheet's rows copied from its temporary
     * file, then the footer. Nothing but the copy buffer is held in memory.
     */
    private function writeContent(): void
    {
        $columnStyles = [];
        $tables = [];

        foreach ($this->sheets as $sheet) {
            $tables[] = self::columns($sheet['options']->columnWidths ?? [], $sheet['columns'], $columnStyles);
        }

        $this->contentPath = TempFile::create('sps_ods_', dirname($this->path));
        $content = @fopen($this->contentPath, 'wb');

        if ($content === false) {
            throw new SpreadsheetException('Cannot create the content part for '.$this->path.'.');
        }

        try {
            $styles = $this->styles->toXml();

            foreach ($columnStyles as $width => $name) {
                $styles .= '<style:style style:name="'.$name.'" style:family="table-column">'
                    .'<style:table-column-properties fo:break-before="auto" style:column-width="'.$width.'"/></style:style>';
            }

            $this->write($content, self::XML_DECLARATION.'<office:document-content '.self::DOCUMENT_NAMESPACES.' office:version="1.3">'
                .'<office:automatic-styles>'.$styles.'</office:automatic-styles>'
                .'<office:body><office:spreadsheet>');

            $ranges = '';

            foreach ($this->sheets as $index => $sheet) {
                $name = XmlEscaper::text($sheet['name']);
                $this->write($content, '<table:table table:name="'.$name.'">'.$tables[$index]);
                $this->copy($content, $this->sheetPaths[$index], $index + 1);
                $this->write($content, '</table:table>');

                if (($sheet['options']->autoFilter ?? false) && $sheet['rows'] > 0 && $sheet['firstRowWidth'] > 0) {
                    $quoted = "'".str_replace("'", "''", $sheet['name'])."'";
                    $last = self::columnLetters($sheet['firstRowWidth'] - 1);
                    $range = $quoted.'.A1:'.$quoted.'.'.$last.$sheet['rows'];
                    $ranges .= '<table:database-range table:name="__Anonymous_Sheet_DB__'.$index.'" table:target-range-address="'
                        .XmlEscaper::text($range).'" table:display-filter-buttons="true"/>';
                }
            }

            $this->write($content, ($ranges === '' ? '' : '<table:database-ranges>'.$ranges.'</table:database-ranges>')
                .'</office:spreadsheet></office:body></office:document-content>');
        } finally {
            fclose($content);
        }

        foreach ($this->sheetPaths as $sheetPath) {
            TempFile::delete($sheetPath);
        }

        $this->sheetPaths = [];
    }

    /**
     * @param  resource  $content
     */
    private function write($content, string $data): void
    {
        if (fwrite($content, $data) !== strlen($data)) {
            throw new SpreadsheetException('Cannot write the content part for '.$this->path.'.');
        }
    }

    /**
     * @param  resource  $content
     */
    private function copy($content, string $sheetPath, int $number): void
    {
        $source = @fopen($sheetPath, 'rb');

        if ($source === false) {
            throw new SpreadsheetException('Cannot read sheet '.$number.' while building '.$this->path.'.');
        }

        try {
            $expected = filesize($sheetPath);

            if ($expected === false || stream_copy_to_stream($source, $content) !== $expected) {
                throw new SpreadsheetException('Cannot copy sheet '.$number.' while building '.$this->path.'.');
            }
        } finally {
            fclose($source);
        }
    }

    private function writeArchive(): void
    {
        $contentPath = $this->contentPath;

        if ($contentPath === null) {
            throw new LogicException('The content part has not been written.');
        }

        $this->zipPath = TempFile::create('sps_ods_', dirname($this->path));
        $zip = new ZipArchive;

        // The empty file from tempnam() is replaced, not opened as an archive.
        if ($zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new SpreadsheetException('Cannot create the archive for '.$this->path.'.');
        }

        $parts = [
            // The media type comes first and stays uncompressed, so applications can sniff it.
            'mimetype' => self::MIMETYPE,
            'META-INF/manifest.xml' => $this->manifest(),
            'meta.xml' => self::XML_DECLARATION.'<office:document-meta '.self::DOCUMENT_NAMESPACES.' office:version="1.3">'
                .'<office:meta><meta:generator>IntrAgile Spreadsheet</meta:generator></office:meta></office:document-meta>',
            'settings.xml' => $this->settings(),
            'styles.xml' => self::XML_DECLARATION.'<office:document-styles '.self::DOCUMENT_NAMESPACES.' office:version="1.3">'
                .'<office:styles><style:default-style style:family="table-cell"/>'
                .'<style:style style:name="Default" style:family="table-cell"/></office:styles></office:document-styles>',
        ];

        foreach ($parts as $name => $contents) {
            if (! $zip->addFromString($name, $contents)) {
                throw new SpreadsheetException('Cannot add '.$name.' to the archive for '.$this->path.'.');
            }
        }

        if (! $zip->setCompressionName('mimetype', ZipArchive::CM_STORE)) {
            throw new SpreadsheetException('Cannot store the media type uncompressed in the archive for '.$this->path.'.');
        }

        // The content file is read when the archive is written, so it must outlive $zip->close().
        if (! @$zip->addFile($contentPath, 'content.xml')
            || ! $zip->setCompressionName('content.xml', ZipArchive::CM_DEFLATE, self::COMPRESSION_LEVEL)) {
            throw new SpreadsheetException('Cannot add the content part to the archive for '.$this->path.'.');
        }

        if (! @$zip->close()) {
            throw new SpreadsheetException('Cannot write the archive for '.$this->path.': '.$zip->getStatusString().'.');
        }

        TempFile::publish($this->zipPath, $this->path);
        $this->zipPath = null;
        TempFile::delete($contentPath);
        $this->contentPath = null;
    }

    private function manifest(): string
    {
        $xml = self::XML_DECLARATION
            .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
            .'<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.self::MIMETYPE.'"/>';

        foreach (['content.xml', 'styles.xml', 'meta.xml', 'settings.xml'] as $part) {
            $xml .= '<manifest:file-entry manifest:full-path="'.$part.'" manifest:media-type="text/xml"/>';
        }

        return $xml.'</manifest:manifest>';
    }

    /**
     * settings.xml: the view state applications restore on open, here the frozen header rows.
     */
    private function settings(): string
    {
        $entries = '';

        foreach ($this->sheets as $sheet) {
            $freeze = $sheet['options']->freezeRows ?? 0;

            if ($freeze <= 0) {
                continue;
            }

            $entries .= '<config:config-item-map-entry config:name="'.XmlEscaper::text($sheet['name']).'">'
                .self::item('HorizontalSplitMode', 'short', 0)
                .self::item('VerticalSplitMode', 'short', 2)
                .self::item('HorizontalSplitPosition', 'int', 0)
                .self::item('VerticalSplitPosition', 'int', $freeze)
                .self::item('ActiveSplitRange', 'short', 2)
                .self::item('PositionLeft', 'int', 0)
                .self::item('PositionRight', 'int', 0)
                .self::item('PositionTop', 'int', 0)
                .self::item('PositionBottom', 'int', $freeze)
                .'</config:config-item-map-entry>';
        }

        return self::XML_DECLARATION.'<office:document-settings '.self::DOCUMENT_NAMESPACES.' office:version="1.3">'
            .'<office:settings><config:config-item-set config:name="ooo:view-settings">'
            .'<config:config-item-map-indexed config:name="Views"><config:config-item-map-entry>'
            .self::item('ViewId', 'string', 'view1')
            .'<config:config-item-map-named config:name="Tables">'.$entries.'</config:config-item-map-named>'
            .self::item('ActiveTable', 'string', $this->sheets[0]['name'])
            .'</config:config-item-map-entry></config:config-item-map-indexed>'
            .'</config:config-item-set></office:settings></office:document-settings>';
    }

    private static function item(string $name, string $type, int|string $value): string
    {
        return '<config:config-item config:name="'.$name.'" config:type="'.$type.'">'.XmlEscaper::text((string) $value).'</config:config-item>';
    }

    /**
     * The table:table-column elements: styled columns where a width is set, repeated defaults elsewhere.
     *
     * @param  array<int, float>  $widths  1-based column => width in characters
     * @param  array<string, string>  $styles  column width (e.g. "5.689cm") => style name, shared across sheets
     */
    private static function columns(array $widths, int $used, array &$styles): string
    {
        ksort($widths);
        $xml = '';
        $next = 1;

        foreach ($widths as $column => $width) {
            $xml .= self::defaultColumns($column - $next);
            // Width in characters of the default font, converted to centimetres at 96 dpi.
            $cm = rtrim(rtrim(sprintf('%.3F', ($width * 7 + 5) / 96 * 2.54), '0'), '.').'cm';
            $name = $styles[$cm] ??= 'co'.(count($styles) + 1);
            $xml .= '<table:table-column table:style-name="'.$name.'"/>';
            $next = $column + 1;
        }

        $xml .= self::defaultColumns($used - $next + 1);

        return $xml === '' ? '<table:table-column/>' : $xml;
    }

    private static function defaultColumns(int $count): string
    {
        return match (true) {
            $count <= 0 => '',
            $count === 1 => '<table:table-column/>',
            default => '<table:table-column table:number-columns-repeated="'.$count.'"/>',
        };
    }

    private function styleAttribute(?Style $style): string
    {
        if ($style === null) {
            return '';
        }

        return ' table:style-name="'.$this->styles->nameFor($style).'"';
    }

    private function date(DateTimeInterface $value, ?Style $style): string
    {
        // Wall-clock time as written; the format has no time zone.
        $iso = $value->format('Y-m-d\TH:i:s');
        $hasTime = ! str_ends_with($iso, 'T00:00:00');
        $name = $this->styles->nameFor($style, true, $hasTime);

        return '<table:table-cell table:style-name="'.$name.'" office:value-type="date" office:date-value="'
            .($hasTime ? $iso : substr($iso, 0, 10)).'"/>';
    }

    private static function gap(int $count): string
    {
        return match (true) {
            $count === 0 => '',
            $count === 1 => '<table:table-cell/>',
            default => '<table:table-cell table:number-columns-repeated="'.$count.'"/>',
        };
    }

    /**
     * The text:p elements of a string: one per line, with tabs and the spaces a reader would
     * collapse (leading, trailing and repeated) spelled out, and characters XML cannot hold removed.
     */
    private static function paragraphs(string $value): string
    {
        if (preg_match(self::NEEDS_ENCODING, $value) === 0) {
            return '<text:p>'.$value.'</text:p>';
        }

        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", XmlEscaper::text($value)));
        $xml = '';

        foreach ($lines as $line) {
            $length = strlen($line);
            $encoded = (string) preg_replace_callback('/ +/', static function (array $match) use ($length): string {
                [$run, $offset] = $match[0];
                $count = strlen($run);
                $edge = $offset === 0 || $offset + $count === $length;
                $spaces = $edge ? $count : $count - 1;
                $element = $spaces === 1 ? '<text:s/>' : '<text:s text:c="'.$spaces.'"/>';

                return $edge ? $element : ' '.($spaces === 0 ? '' : $element);
            }, $line, -1, $replaced, PREG_OFFSET_CAPTURE);

            $encoded = str_replace("\t", '<text:tab/>', $encoded);
            $xml .= $encoded === '' ? '<text:p/>' : '<text:p>'.$encoded.'</text:p>';
        }

        return $xml;
    }

    /**
     * An Excel-style formula body (without "=") as the text after "of:=". A1 references and
     * ranges become [.A1] and [.A1:.B2], and "," argument separators become ";". Formulas with
     * sheet references, quoted names or structured references are left as written.
     */
    private static function formula(string $formula): string
    {
        $segments = preg_split('/("(?:[^"]|"")*"?)/', $formula, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$formula];
        $convertible = true;

        foreach ($segments as $index => $segment) {
            // Odd segments are string literals, which never take part in the conversion.
            if ($index % 2 === 0 && strpbrk($segment, "!'[]{}") !== false) {
                $convertible = false;
            }
        }

        if ($convertible) {
            foreach ($segments as $index => $segment) {
                if ($index % 2 === 1) {
                    continue;
                }

                $cell = '\$?[A-Za-z]{1,3}\$?[0-9]+';
                $segments[$index] = str_replace(',', ';', (string) preg_replace_callback(
                    '/(?<![A-Za-z0-9_.])('.$cell.')(?::('.$cell.'))?(?![A-Za-z0-9_(])/',
                    static fn (array $m): string => '[.'.$m[1].(isset($m[2]) ? ':.'.$m[2] : '').']',
                    $segment,
                ));
            }
        }

        return XmlEscaper::text(implode('', $segments));
    }

    /**
     * Shortest representation that round-trips, independent of the locale.
     */
    private static function number(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Spreadsheet numbers must be finite; got '.var_export($value, true).'.');
        }

        $text = var_export($value, true);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }

    private static function columnLetters(int $index): string
    {
        $letters = '';

        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $letters = chr(65 + ($n - 1) % 26).$letters;
        }

        return $letters;
    }

    private static function validateOptions(?SheetOptions $options): void
    {
        if ($options === null) {
            return;
        }

        if ($options->freezeRows < 0 || $options->freezeRows >= self::MAX_ROWS) {
            throw new InvalidArgumentException(sprintf('freezeRows must be between 0 and %d.', self::MAX_ROWS - 1));
        }

        foreach ($options->columnWidths as $column => $width) {
            if ($column < 1 || $column > self::MAX_COLUMNS) {
                throw new InvalidArgumentException(sprintf('Column width key %s is not a column number between 1 and %d.', var_export($column, true), self::MAX_COLUMNS));
            }

            if (! is_finite((float) $width) || $width <= 0 || $width > 255) {
                throw new InvalidArgumentException(sprintf('Column %d width must be greater than 0 and at most 255.', $column));
            }
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The writer is closed.');
        }
    }

    /**
     * Drops all partial output and marks the writer closed; the target is never created.
     */
    private function abort(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }

        foreach ($this->sheetPaths as $sheetPath) {
            TempFile::delete($sheetPath);
        }

        foreach ([$this->contentPath, $this->zipPath] as $temp) {
            if ($temp !== null) {
                TempFile::delete($temp);
            }
        }

        $this->sheetPaths = [];
        $this->contentPath = null;
        $this->zipPath = null;
        $this->buffer = '';
        $this->closed = true;
    }
}
