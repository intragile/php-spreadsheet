<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Support;

use DateTimeInterface;
use IntrAgile\Spreadsheet\Internal\Date\SerialDate;
use RuntimeException;
use ZipArchive;

/**
 * Streams large test workbooks to disk with Excel's layout: shared strings, cell references and a
 * built-in date style (s="1"). Rows are written straight to temporary files, so building a
 * workbook of any size takes constant memory.
 *
 * Strings are deduplicated through a bounded map; once it is full, new strings are appended to the
 * shared-string table even if they repeat, which spreadsheet applications accept.
 */
final class XlsxFixtureBuilder
{
    private const DEDUPLICATED_STRINGS = 10_000;

    /** @var resource */
    private $sheet;

    /** @var resource */
    private $strings;

    private string $sheetPath;

    private string $stringsPath;

    /** @var array<string, int> */
    private array $indexes = [];

    private int $stringCount = 0;

    private int $row = 0;

    private string $buffer = '';

    public function __construct(private readonly string $path)
    {
        $this->sheetPath = Fixtures::path('xml');
        $this->stringsPath = Fixtures::path('xml');
        $this->sheet = self::open($this->sheetPath);
        $this->strings = self::open($this->stringsPath);

        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        fwrite($this->sheet, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.$main.'"><sheetData>');
        // count and uniqueCount are optional and unknown until the end, so they are left out.
        fwrite($this->strings, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><sst xmlns="'.$main.'">');
    }

    /**
     * @param  list<int|float|string|bool|DateTimeInterface|null>  $cells
     */
    public function addRow(array $cells): self
    {
        $this->row++;
        $xml = '<row r="'.$this->row.'">';

        foreach ($cells as $column => $value) {
            $reference = self::column($column).$this->row;

            $xml .= match (true) {
                $value === null => '',
                is_bool($value) => '<c r="'.$reference.'" t="b"><v>'.($value ? '1' : '0').'</v></c>',
                is_int($value), is_float($value) => '<c r="'.$reference.'"><v>'.$value.'</v></c>',
                $value instanceof DateTimeInterface => '<c r="'.$reference.'" s="1"><v>'.SerialDate::fromDateTime($value).'</v></c>',
                default => '<c r="'.$reference.'" t="s"><v>'.$this->stringIndex($value).'</v></c>',
            };
        }

        $this->buffer .= $xml.'</row>';

        if (strlen($this->buffer) > 65536) {
            fwrite($this->sheet, $this->buffer);
            $this->buffer = '';
        }

        return $this;
    }

    public function close(): string
    {
        fwrite($this->sheet, $this->buffer.'</sheetData></worksheet>');
        fwrite($this->strings, '</sst>');
        fclose($this->sheet);
        fclose($this->strings);
        $this->indexes = [];

        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create '.$this->path);
        }

        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $pkg = 'http://schemas.openxmlformats.org/package/2006/relationships';

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.$pkg.'"><Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.$pkg.'">'
            .'<Relationship Id="rId1" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.$rel.'/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="'.$rel.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.$main.'"><cellXfs count="2"><xf numFmtId="0"/><xf numFmtId="14" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->addFile($this->sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->addFile($this->stringsPath, 'xl/sharedStrings.xml');

        foreach (['xl/worksheets/sheet1.xml', 'xl/sharedStrings.xml'] as $name) {
            $zip->setCompressionName($name, ZipArchive::CM_DEFLATE, 1);
        }

        if (! $zip->close()) {
            throw new RuntimeException('Cannot write '.$this->path);
        }

        return $this->path;
    }

    private function stringIndex(string $value): int
    {
        if (isset($this->indexes[$value])) {
            return $this->indexes[$value];
        }

        $escaped = htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        fwrite($this->strings, '<si><t xml:space="preserve">'.$escaped.'</t></si>');
        $index = $this->stringCount++;

        if (count($this->indexes) < self::DEDUPLICATED_STRINGS) {
            $this->indexes[$value] = $index;
        }

        return $index;
    }

    private static function column(int $index): string
    {
        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }

    /**
     * @return resource
     */
    private static function open(string $path)
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot write '.$path);
        }

        return $handle;
    }
}
