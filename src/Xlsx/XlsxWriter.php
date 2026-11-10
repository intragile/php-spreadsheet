<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

use DateTimeInterface;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Contracts\Writer;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\ColumnWidth;
use IntrAgile\Spreadsheet\Internal\Date\SerialDate;
use IntrAgile\Spreadsheet\Internal\NumberText;
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
 * Streams rows into an XLSX workbook in constant memory.
 *
 * Each sheet is written to its own temporary file as rows arrive; only the current sheet's file is
 * open. Strings are stored inline (t="inlineStr"), so no shared-string table grows with the data.
 * close() assembles the package into a temporary archive next to the target and renames it into
 * place, so the target appears complete or not at all.
 *
 * A cell's own style (Cell::text(), Cell::formula(), Cell::of()) replaces the row style for that
 * cell; the two are not merged.
 */
final class XlsxWriter implements Writer
{
    public const MAX_ROWS = 1_048_576;

    private const BUFFER_BYTES = 65_536;

    /**
     * Deflate level of the sheet parts. Sheet XML is repetitive, so level 1 already shrinks it
     * about tenfold; higher levels cost several times the time for a few percent less size.
     */
    private const COMPRESSION_LEVEL = 1;

    /** Blank bytes reserved for the cols element of the widened date columns. */
    private const COLS_ROOM = 2048;

    /** Upper bound of one explicit col element, reserved per column width set in SheetOptions. */
    private const COLS_ENTRY_BYTES = 72;

    /** Excel's default column width in characters; narrower date columns are left alone. */
    private const DEFAULT_COLUMN_WIDTH = 8.43;

    private const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const NS_RELATIONSHIPS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const NS_PACKAGE_RELATIONSHIPS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    private const XML_DECLARATION = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n";

    /**
     * Matches text that needs escaping or cleaning; text without a match, and valid UTF-8, is
     * written as is. Carriage returns are included so they survive as character references.
     */
    private const NEEDS_ESCAPING = '/[\x00-\x08\x0B-\x1F&<>\x{FFFE}\x{FFFF}]/u';

    private readonly StyleRegistry $styles;

    /** @var resource|null */
    private $handle = null;

    /** @var list<string> temporary file of every sheet, in workbook order */
    private array $sheetPaths = [];

    /** @var list<array{name: string, filter: ?string}> */
    private array $sheets = [];

    /** @var array<string, true> lowercased sheet names */
    private array $sheetNames = [];

    private ?string $zipPath = null;

    private ?SheetOptions $options = null;

    private string $buffer = '';

    private int $rowNumber = 0;

    private int $firstRowWidth = 0;

    /** Byte offset of the blank room in the sheet file that the cols element is written into. */
    private int $colsAt = 0;

    /** Size of that room in bytes. */
    private int $colsRoom = 0;

    /** @var array<int, float> zero-based column => characters its widest date needs */
    private array $dateWidths = [];

    /** @var array<int, float> cell format index => characters a date in that format needs */
    private array $dateWidthByFormat = [];

    /** @var array<int, string> column letters by zero-based index, filled on demand */
    private array $letters = [];

    private bool $hasFormulas = false;

    private bool $closed = false;

    /** True once partial output was discarded; close() then reports that no file exists. */
    private bool $aborted = false;

    public function __construct(private readonly string $path)
    {
        $this->styles = new StyleRegistry;
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

        $precision = NumberText::begin();

        try {
            if ($this->handle === null) {
                $this->openSheet('Sheet1', null);
            }

            $r = ++$this->rowNumber;

            if ($r > self::MAX_ROWS) {
                throw new SpreadsheetException(sprintf('A sheet holds at most %d rows.', self::MAX_ROWS));
            }

            $id = $rowStyle === null ? 0 : $this->styles->idFor($rowStyle);
            $s = $id === 0 ? '' : ' s="'.$id.'"';

            // Every cell names its reference; readers that count unreferenced cells by position
            // would otherwise misplace the cells that follow a skipped one.
            $column = 0;
            $xml = '<row r="'.$r.'">';

            foreach ($values as $value) {
                if (($value === null || $value === '') && $s === '') {
                    $column++;

                    continue;
                }

                if ($column > ColumnName::MAX_INDEX) {
                    throw new SpreadsheetException(sprintf('Row %d has more than %d cells; a sheet holds at most %d columns.', $r, ColumnName::MAX_INDEX + 1, ColumnName::MAX_INDEX + 1));
                }

                $at = ' r="'.$this->letter($column).$r.'"';
                $c = '<c'.$at.$s;
                $column++;

                if (is_string($value)) {
                    $xml .= $value === '' ? $c.'/>' : $c.' t="inlineStr"><is>'.self::text($value).'</is></c>';
                } elseif (is_int($value)) {
                    $xml .= $c.'><v>'.$value.'</v></c>';
                } elseif (is_float($value)) {
                    $xml .= $c.'><v>'.NumberText::format($value).'</v></c>';
                } elseif ($value === null) {
                    $xml .= $c.'/>';
                } elseif (is_bool($value)) {
                    $xml .= $c.' t="b"><v>'.($value ? '1' : '0').'</v></c>';
                } elseif ($value instanceof DateTimeInterface) {
                    $xml .= $this->date($at, $column - 1, $value, $rowStyle);
                } elseif ($value instanceof Cell) {
                    $xml .= $this->cell($at, $column - 1, $value, $rowStyle);
                } else {
                    throw new InvalidArgumentException(sprintf('Unsupported cell value of type %s in column %d of row %d.', get_debug_type($value), $column, $r));
                }
            }

            if ($column > ColumnName::MAX_INDEX + 1) {
                throw new SpreadsheetException(sprintf('Row %d has %d cells; a sheet holds at most %d columns.', $r, $column, ColumnName::MAX_INDEX + 1));
            }

            if ($r === 1) {
                $this->firstRowWidth = $column;
            }

            $this->buffer .= $xml.'</row>';

            if (strlen($this->buffer) >= self::BUFFER_BYTES) {
                $this->flush();
            }
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        } finally {
            NumberText::end($precision);
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

    /**
     * Throws a LogicException when an earlier failure aborted the write, so no file was written.
     */
    public function close(): void
    {
        if ($this->closed) {
            if ($this->aborted) {
                throw new LogicException('The write was aborted by an earlier error; no file was written to '.$this->path.'.');
            }

            return;
        }

        try {
            if ($this->sheets === []) {
                $this->openSheet('Sheet1', null);
            }

            $this->finishSheet();
            $this->writeArchive();
            $this->closed = true;
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    private function writeArchive(): void
    {
        $this->zipPath = TempFile::create('sps_xlsx_', dirname($this->path));
        $zip = new ZipArchive;

        // The empty file from tempnam() is replaced, not opened as an archive.
        if ($zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new SpreadsheetException('Cannot create the archive for '.$this->path.'.');
        }

        $parts = [
            '[Content_Types].xml' => $this->contentTypes(),
            '_rels/.rels' => self::XML_DECLARATION.'<Relationships xmlns="'.self::NS_PACKAGE_RELATIONSHIPS.'">'
                .'<Relationship Id="rId1" Type="'.self::NS_RELATIONSHIPS.'/officeDocument" Target="xl/workbook.xml"/>'
                .'<Relationship Id="rId2" Type="'.self::NS_RELATIONSHIPS.'/extended-properties" Target="docProps/app.xml"/>'
                .'</Relationships>',
            'docProps/app.xml' => self::XML_DECLARATION
                .'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties">'
                .'<Application>IntrAgile Spreadsheet</Application></Properties>',
            'xl/workbook.xml' => $this->workbook(),
            'xl/_rels/workbook.xml.rels' => $this->workbookRelationships(),
            'xl/styles.xml' => $this->styles->toXml(),
        ];

        foreach ($parts as $name => $contents) {
            if (! $zip->addFromString($name, $contents)) {
                throw new SpreadsheetException('Cannot add '.$name.' to the archive for '.$this->path.'.');
            }
        }

        // Sheet files are read when the archive is written, so they must outlive $zip->close().
        foreach ($this->sheetPaths as $index => $sheetPath) {
            $entry = 'xl/worksheets/sheet'.($index + 1).'.xml';

            if (! @$zip->addFile($sheetPath, $entry)
                || ! $zip->setCompressionName($entry, ZipArchive::CM_DEFLATE, self::COMPRESSION_LEVEL)) {
                throw new SpreadsheetException('Cannot add sheet '.($index + 1).' to the archive for '.$this->path.'.');
            }
        }

        if (! @$zip->close()) {
            throw new SpreadsheetException('Cannot write the archive for '.$this->path.': '.$zip->getStatusString().'.');
        }

        TempFile::publish($this->zipPath, $this->path);
        $this->zipPath = null;

        foreach ($this->sheetPaths as $sheetPath) {
            TempFile::delete($sheetPath);
        }

        $this->sheetPaths = [];
    }

    private function openSheet(string $name, ?SheetOptions $options): void
    {
        $path = TempFile::create('sps_xlsx_', dirname($this->path));
        $this->sheetPaths[] = $path;
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open '.$path.' for writing.');
        }

        $this->handle = $handle;
        $this->sheets[] = ['name' => $name, 'filter' => null];
        $this->sheetNames[mb_strtolower($name, 'UTF-8')] = true;
        $this->options = $options;
        $this->rowNumber = 0;
        $this->firstRowWidth = 0;
        $this->dateWidths = [];

        $view = '<sheetView workbookViewId="0"'.(count($this->sheets) === 1 ? ' tabSelected="1"' : '');
        $freeze = $options->freezeRows ?? 0;
        $view .= $freeze > 0
            ? '><pane ySplit="'.$freeze.'" topLeftCell="A'.($freeze + 1).'" activePane="bottomLeft" state="frozen"/>'
                .'<selection pane="bottomLeft" activeCell="A'.($freeze + 1).'" sqref="A'.($freeze + 1).'"/></sheetView>'
            : '/>';

        $this->buffer = self::XML_DECLARATION
            .'<worksheet xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_RELATIONSHIPS.'">'
            .'<sheetViews>'.$view.'</sheetViews>'
            .'<sheetFormatPr defaultRowHeight="15"/>';

        // Date columns are only known once their rows are written, so the cols element goes into
        // blank room reserved here; whitespace between elements is insignificant.
        $this->colsAt = strlen($this->buffer);
        $this->colsRoom = self::COLS_ROOM + self::COLS_ENTRY_BYTES * count($options->columnWidths ?? []);
        $this->buffer .= str_repeat(' ', $this->colsRoom).'<sheetData>';
    }

    /**
     * Closes the current sheet's XML and its file; the next sheet starts with a fresh handle.
     */
    private function finishSheet(): void
    {
        $handle = $this->handle;

        if ($handle === null) {
            return;
        }

        $this->buffer .= '</sheetData>';

        if (($this->options->autoFilter ?? false) && $this->rowNumber > 0 && $this->firstRowWidth > 0) {
            $last = $this->letter($this->firstRowWidth - 1);
            $this->buffer .= '<autoFilter ref="A1:'.$last.$this->rowNumber.'"/>';
            $this->sheets[count($this->sheets) - 1]['filter'] = '$A$1:$'.$last.'$'.$this->rowNumber;
        }

        $this->buffer .= '</worksheet>';
        $this->flush();
        $this->handle = null;
        $cols = $this->cols();

        if ($cols !== '' && strlen($cols) <= $this->colsRoom
            && (fseek($handle, $this->colsAt) !== 0 || fwrite($handle, $cols) !== strlen($cols))) {
            throw new RuntimeException('Cannot write to '.end($this->sheetPaths).'.');
        }

        if (! fclose($handle)) {
            throw new RuntimeException('Cannot finish writing '.end($this->sheetPaths).'.');
        }

        if (strlen($cols) > $this->colsRoom) {
            $this->rewriteWithCols($cols);
        }
    }

    /**
     * The cols element of the current sheet: explicit widths, and a width that fits for every
     * other column holding a date wider than the default column. Neighbours of equal width share
     * one element.
     */
    private function cols(): string
    {
        $widths = [];

        foreach ($this->options->columnWidths ?? [] as $column => $width) {
            $widths[$column] = ColumnWidth::toExcel((float) $width);
        }

        foreach ($this->dateWidths as $index => $characters) {
            if ($characters > self::DEFAULT_COLUMN_WIDTH && ! isset($widths[$index + 1])) {
                $widths[$index + 1] = ColumnWidth::toExcel($characters);
            }
        }

        if ($widths === []) {
            return '';
        }

        ksort($widths);
        $xml = '';
        $first = $last = null;
        $current = 0.0;

        foreach ($widths as $column => $width) {
            if ($last !== null && $column === $last + 1 && $width === $current) {
                $last = $column;

                continue;
            }

            if ($first !== null) {
                $xml .= self::col($first, (int) $last, $current);
            }

            $first = $last = $column;
            $current = $width;
        }

        return '<cols>'.$xml.self::col((int) $first, (int) $last, $current).'</cols>';
    }

    private static function col(int $first, int $last, float $width): string
    {
        return '<col min="'.$first.'" max="'.$last.'" width="'.NumberText::fromFloat($width).'" customWidth="1"/>';
    }

    /**
     * Copies the current sheet file into a new one with the cols element in place of the reserved
     * room, for a cols element too large for it.
     */
    private function rewriteWithCols(string $cols): void
    {
        $oldPath = (string) end($this->sheetPaths);
        $newPath = TempFile::create('sps_xlsx_', dirname($this->path));
        array_pop($this->sheetPaths);
        $this->sheetPaths[] = $newPath;
        $old = @fopen($oldPath, 'rb');
        $new = @fopen($newPath, 'wb');

        try {
            if ($old === false || $new === false
                || stream_copy_to_stream($old, $new, $this->colsAt) !== $this->colsAt
                || fwrite($new, $cols) !== strlen($cols)
                || fseek($old, $this->colsAt + $this->colsRoom) !== 0
                || stream_copy_to_stream($old, $new) === false) {
                throw new RuntimeException('Cannot rewrite '.$oldPath.' into '.$newPath.'.');
            }
        } finally {
            if ($old !== false) {
                fclose($old);
            }

            if ($new !== false && ! fclose($new)) {
                throw new RuntimeException('Cannot finish writing '.$newPath.'.');
            }

            TempFile::delete($oldPath);
        }
    }

    private function letter(int $column): string
    {
        return $this->letters[$column] ??= ColumnName::fromIndex($column);
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
     * Records the width the date needs in its column.
     *
     * @param  string  $at  the cell's r attribute, or '' when it follows its left neighbour
     * @param  int  $index  zero-based column
     */
    private function date(string $at, int $index, DateTimeInterface $value, ?Style $style): string
    {
        $serial = SerialDate::fromDateTime($value);
        $hasTime = $serial !== floor($serial);
        $id = $this->styles->idFor($style, true, $hasTime);
        $characters = $this->dateWidthByFormat[$id] ??= ColumnWidth::forDateFormat(
            $style?->getNumberFormat() ?? ($hasTime ? StyleRegistry::DATE_TIME_FORMAT : StyleRegistry::DATE_FORMAT)
        );

        if (($this->dateWidths[$index] ?? 0.0) < $characters) {
            $this->dateWidths[$index] = $characters;
        }

        return '<c'.$at.' s="'.$id.'"><v>'.NumberText::format($serial).'</v></c>';
    }

    /**
     * @param  string  $at  the cell's r attribute, or '' when it follows its left neighbour
     * @param  int  $index  zero-based column
     * @return string the cell XML, or '' when the cell is empty and unstyled
     */
    private function cell(string $at, int $index, Cell $cell, ?Style $rowStyle): string
    {
        $style = $cell->style ?? $rowStyle;
        $value = $cell->value;

        if ($cell->kind === Cell::KIND_FORMULA) {
            $this->hasFormulas = true;
            $id = $this->styles->idFor($style);

            return '<c'.$at.($id === 0 ? '' : ' s="'.$id.'"').'><f>'.XmlEscaper::text(is_string($value) ? substr($value, 1) : '').'</f></c>';
        }

        if ($value instanceof DateTimeInterface) {
            return $this->date($at, $index, $value, $style);
        }

        $id = $this->styles->idFor($style);
        $s = $id === 0 ? '' : ' s="'.$id.'"';
        $c = '<c'.$at.$s;

        return match (true) {
            $value === null, $value === '' => $s === '' ? '' : $c.'/>',
            is_string($value) => $c.' t="inlineStr"><is>'.self::text($value).'</is></c>',
            is_bool($value) => $c.' t="b"><v>'.($value ? '1' : '0').'</v></c>',
            is_int($value) => $c.'><v>'.$value.'</v></c>',
            default => $c.'><v>'.NumberText::format($value).'</v></c>',
        };
    }

    /**
     * The <t> element of an inline string: escaped, stripped of characters XML cannot hold, and
     * marked to keep leading or trailing whitespace.
     */
    private static function text(string $value): string
    {
        if (preg_match(self::NEEDS_ESCAPING, $value) !== 0) {
            // Rare path: keeping whitespace unconditionally is cheaper than re-checking the edges.
            return '<t xml:space="preserve">'.str_replace("\r", '&#13;', XmlEscaper::text($value)).'</t>';
        }

        $first = $value[0];
        $last = $value[-1];

        if ($first === ' ' || $first === "\t" || $first === "\n" || $last === ' ' || $last === "\t" || $last === "\n") {
            return '<t xml:space="preserve">'.$value.'</t>';
        }

        return '<t>'.$value.'</t>';
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
            if ($column < 1 || $column > ColumnName::MAX_INDEX + 1) {
                throw new InvalidArgumentException(sprintf('Column width key %s is not a column number between 1 and %d.', var_export($column, true), ColumnName::MAX_INDEX + 1));
            }

            if (! is_finite((float) $width) || $width <= 0 || $width > 255) {
                throw new InvalidArgumentException(sprintf('Column %d width must be greater than 0 and at most 255.', $column));
            }
        }
    }

    private function workbook(): string
    {
        $sheets = '';
        $filters = '';

        foreach ($this->sheets as $index => $sheet) {
            $sheets .= '<sheet name="'.XmlEscaper::text($sheet['name']).'" sheetId="'.($index + 1).'" r:id="rId'.($index + 1).'"/>';

            if ($sheet['filter'] !== null) {
                $quoted = "'".str_replace("'", "''", $sheet['name'])."'";
                $filters .= '<definedName name="_xlnm._FilterDatabase" localSheetId="'.$index.'" hidden="1">'
                    .XmlEscaper::text($quoted.'!'.$sheet['filter']).'</definedName>';
            }
        }

        return self::XML_DECLARATION
            .'<workbook xmlns="'.self::NS_MAIN.'" xmlns:r="'.self::NS_RELATIONSHIPS.'">'
            .'<bookViews><workbookView/></bookViews>'
            .'<sheets>'.$sheets.'</sheets>'
            .($filters === '' ? '' : '<definedNames>'.$filters.'</definedNames>')
            // Formulas are written without cached results, so they must be computed on open.
            .($this->hasFormulas ? '<calcPr calcId="0" fullCalcOnLoad="1"/>' : '')
            .'</workbook>';
    }

    private function workbookRelationships(): string
    {
        $xml = self::XML_DECLARATION.'<Relationships xmlns="'.self::NS_PACKAGE_RELATIONSHIPS.'">';
        $count = count($this->sheets);

        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Relationship Id="rId'.$i.'" Type="'.self::NS_RELATIONSHIPS.'/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return $xml.'<Relationship Id="rId'.($count + 1).'" Type="'.self::NS_RELATIONSHIPS.'/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    private function contentTypes(): string
    {
        $type = 'application/vnd.openxmlformats-officedocument.spreadsheetml';
        $xml = self::XML_DECLARATION
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="'.$type.'.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="'.$type.'.styles+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';

        $count = count($this->sheets);

        for ($i = 1; $i <= $count; $i++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="'.$type.'.worksheet+xml"/>';
        }

        return $xml.'</Types>';
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

        if ($this->zipPath !== null) {
            TempFile::delete($this->zipPath);
            $this->zipPath = null;
        }

        $this->sheetPaths = [];
        $this->buffer = '';
        $this->closed = true;
        $this->aborted = true;
    }
}
