<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Xlsx;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMNodeList;
use DOMXPath;
use Generator;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Style;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use IntrAgile\Spreadsheet\Xlsx\ColumnName;
use IntrAgile\Spreadsheet\Xlsx\XlsxReader;
use IntrAgile\Spreadsheet\Xlsx\XlsxWriter;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use ZipArchive;

final class XlsxWriterTest extends TestCase
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    public function test_roundtrip_types(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow([
            42, 3.14, 'szöveg', true, new DateTimeImmutable('2025-01-31'), null, Cell::formula('=A1*2'),
        ])->close();

        $rows = self::readRows($path);

        // The formula has no cached value and the null is not stored, so the row ends at the date.
        $this->assertSame([1], array_keys($rows));
        $this->assertCount(5, $rows[1]);
        $this->assertSame([42, 3.14, 'szöveg', true], array_slice($rows[1], 0, 4));
        $this->assertEquals(new DateTimeImmutable('2025-01-31'), $rows[1][4]);
        $this->assertStringContainsString('<f>A1*2</f>', self::part($path, 'xl/worksheets/sheet1.xml'));
    }

    public function test_strings_never_coerced(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow(['00123', '=1+1', '1e5'])->addRow([Cell::text('=2+2')])->close();

        $rows = self::readRows($path);

        $this->assertSame(['00123', '=1+1', '1e5'], $rows[1]);
        $this->assertSame(['=2+2'], $rows[2]);
        $this->assertStringNotContainsString('<f>', self::part($path, 'xl/worksheets/sheet1.xml'));
    }

    public function test_control_chars_stripped(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow(["a\x01b", "c\x00d\x1Fe", "bad \xC3 utf8", 'a < b & c > "d"'])->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::RDONLY));
        $zip->close();

        $this->assertSame(['ab', 'cde', 'bad  utf8', 'a < b & c > "d"'], self::readRows($path)[1]);
    }

    public function test_surrounding_whitespace_preserved(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow(['  lead', "trail\t", "multi\nline"])->close();

        $this->assertSame(['  lead', "trail\t", "multi\nline"], self::readRows($path)[1]);
    }

    public function test_styles(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = new XlsxWriter($path);

        for ($i = 0; $i < 100; $i++) {
            // A fresh but equal instance each time must share the registered entry.
            $writer->addRow(['a', 'b', 1], Style::make()->bold()->fill('DDEBF7')->border());
        }

        $writer->addRow(['plain'])->close();

        $styles = self::xpath(self::part($path, 'xl/styles.xml'));
        $xfs = self::query($styles, '/m:styleSheet/m:cellXfs/m:xf');
        $this->assertSame(2, $xfs->length);

        $xf = $xfs->item(1);
        $this->assertInstanceOf(DOMElement::class, $xf);
        $font = self::query($styles, '/m:styleSheet/m:fonts/m:font')->item((int) $xf->getAttribute('fontId'));
        $fill = self::query($styles, '/m:styleSheet/m:fills/m:fill')->item((int) $xf->getAttribute('fillId'));
        $border = self::query($styles, '/m:styleSheet/m:borders/m:border')->item((int) $xf->getAttribute('borderId'));
        $this->assertInstanceOf(DOMElement::class, $font);
        $this->assertInstanceOf(DOMElement::class, $fill);
        $this->assertInstanceOf(DOMElement::class, $border);

        $this->assertSame(1, self::query($styles, 'm:b', $font)->length);
        $this->assertSame('FFDDEBF7', self::attr($styles, 'm:patternFill[@patternType="solid"]/m:fgColor', 'rgb', $fill));
        foreach (['left', 'right', 'top', 'bottom'] as $side) {
            $this->assertSame('thin', self::attr($styles, 'm:'.$side, 'style', $border));
            $this->assertSame('FF000000', self::attr($styles, 'm:'.$side.'/m:color', 'rgb', $border));
        }

        $sheet = self::part($path, 'xl/worksheets/sheet1.xml');
        $this->assertSame(300, substr_count($sheet, 's="1"'));
        $this->assertStringContainsString('<row r="101"><c r="A101" t="inlineStr">', $sheet);
    }

    public function test_all_style_properties_serialized(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = new XlsxWriter($path);
        $writer->addRow([Cell::text('x', Style::make()->italic())]);
        $writer->addRow([Cell::text('x', Style::make()->fontColor('C00000'))]);
        $writer->addRow([Cell::text('x', Style::make()->align('center'))]);
        $writer->addRow([Cell::text('x', Style::make()->wrap())]);
        $writer->close();

        $styles = self::xpath(self::part($path, 'xl/styles.xml'));
        $sheet = self::xpath(self::part($path, 'xl/worksheets/sheet1.xml'));
        $xf = function (int $row) use ($styles, $sheet): DOMElement {
            $id = (int) self::attr($sheet, '//m:row[@r="'.$row.'"]/m:c', 's');
            $element = self::query($styles, '/m:styleSheet/m:cellXfs/m:xf')->item($id);
            $this->assertInstanceOf(DOMElement::class, $element);

            return $element;
        };
        $font = fn (DOMElement $xf): DOMElement => self::nth($styles, '/m:styleSheet/m:fonts/m:font', (int) $xf->getAttribute('fontId'));

        $this->assertSame(1, self::query($styles, 'm:i', $font($xf(1)))->length);
        $this->assertSame('FFC00000', self::attr($styles, 'm:color', 'rgb', $font($xf(2))));
        $this->assertSame('center', self::attr($styles, 'm:alignment', 'horizontal', $xf(3)));
        $this->assertSame('1', $xf(3)->getAttribute('applyAlignment'));
        $this->assertSame('1', self::attr($styles, 'm:alignment', 'wrapText', $xf(4)));
    }

    public function test_number_format(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->addRow([1234.5], Style::make()->numberFormat('#,##0.00'))
            ->addRow([Cell::of(0.25, Style::make()->numberFormat('0%'))])
            ->close();

        $this->assertSame('#,##0.00', self::numberFormatOf($path, 'A1'));
        $this->assertSame('0%', self::numberFormatOf($path, 'A2'));
        $this->assertSame([1 => [1234.5], 2 => [0.25]], self::readRows($path));
    }

    public function test_dates_get_default_formats_and_keep_explicit_ones(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow([
            new DateTimeImmutable('2025-01-31'),
            new DateTimeImmutable('2025-01-31 13:14:15'),
            Cell::of(new DateTimeImmutable('2025-02-01'), Style::make()->numberFormat('yyyy.mm.dd')),
            Cell::of(new DateTimeImmutable('2025-02-02'), Style::make()->bold()),
        ])->close();

        $this->assertSame('yyyy-mm-dd', self::numberFormatOf($path, 'A1'));
        $this->assertSame('yyyy-mm-dd hh:mm:ss', self::numberFormatOf($path, 'B1'));
        $this->assertSame('yyyy.mm.dd', self::numberFormatOf($path, 'C1'));
        $this->assertSame('yyyy-mm-dd', self::numberFormatOf($path, 'D1'));

        $row = self::readRows($path)[1];
        $this->assertEquals(new DateTimeImmutable('2025-01-31'), $row[0]);
        $this->assertEquals(new DateTimeImmutable('2025-01-31 13:14:15'), $row[1]);
        $this->assertEquals(new DateTimeImmutable('2025-02-01'), $row[2]);
        $this->assertEquals(new DateTimeImmutable('2025-02-02'), $row[3]);
    }

    public function test_cell_style_replaces_row_style(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow(['row', Cell::text('cell', Style::make()->italic()), null], Style::make()->bold())->close();

        $sheet = self::part($path, 'xl/worksheets/sheet1.xml');
        $styles = self::xpath(self::part($path, 'xl/styles.xml'));

        // A styled empty cell keeps the row style, so header fills run across blank columns.
        $this->assertStringContainsString(
            '<row r="1"><c r="A1" s="1" t="inlineStr"><is><t>row</t></is></c><c r="B1" s="2" t="inlineStr"><is><t>cell</t></is></c><c r="C1" s="1"/></row>',
            $sheet,
        );
        $italicFont = self::nth($styles, '/m:styleSheet/m:fonts/m:font', (int) self::nth($styles, '/m:styleSheet/m:cellXfs/m:xf', 2)->getAttribute('fontId'));
        $this->assertSame(0, self::query($styles, 'm:b', $italicFont)->length);
    }

    public function test_cells_after_a_gap_carry_their_reference(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->addRow([1, null, 3, '', 5])
            ->addRow([null, 'x'])
            ->addRow([Cell::text(''), Cell::text('y'), 'z'])
            ->close();

        $sheet = self::part($path, 'xl/worksheets/sheet1.xml');

        $this->assertStringContainsString('<row r="1"><c r="A1"><v>1</v></c><c r="C1"><v>3</v></c><c r="E1"><v>5</v></c></row>', $sheet);
        $this->assertStringContainsString('<row r="2"><c r="B2" t="inlineStr">', $sheet);
        $this->assertStringContainsString('<row r="3"><c r="B3" t="inlineStr"><is><t>y</t></is></c><c r="C3" t="inlineStr">', $sheet);
        $this->assertSame([1 => [1, null, 3, null, 5], 2 => [null, 'x'], 3 => [null, 'y', 'z']], self::readRows($path));
    }

    public function test_every_cell_of_a_gapped_row_carries_its_reference(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->addRow([1, null, null, 'a', true, null, 2.5, new DateTimeImmutable('2024-01-02'), Cell::formula('=A1'), 'z'])
            ->close();

        $sheet = self::xpath(self::part($path, 'xl/worksheets/sheet1.xml'));
        $refs = [];
        foreach (self::query($sheet, '//m:row[@r="1"]/m:c') as $c) {
            $this->assertInstanceOf(DOMElement::class, $c);
            $refs[] = $c->getAttribute('r');
        }

        $this->assertSame(['A1', 'D1', 'E1', 'G1', 'H1', 'I1', 'J1'], $refs);
    }

    public function test_too_many_columns_throws_and_aborts(): void
    {
        $writer = new XlsxWriter(Fixtures::path('xlsx'));

        try {
            $writer->addRow(array_fill(0, ColumnName::MAX_INDEX + 2, 1));
            $this->fail('Expected exception');
        } catch (SpreadsheetException $e) {
            $this->assertStringContainsString('16384 columns', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $writer->addRow([1]);
    }

    public function test_sheet_options(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->sheet('Data', new SheetOptions(columnWidths: [1 => 30], freezeRows: 1, autoFilter: true))
            ->addRows([['a', 'b', 'c'], [1, 2, 3], [4, 5, 6]])
            ->close();

        $sheet = self::part($path, 'xl/worksheets/sheet1.xml');

        // 30 characters plus the 5-pixel cell padding, so Excel's width dialog shows 30.
        $this->assertStringContainsString('<col min="1" max="1" width="30.7109375" customWidth="1"/>', $sheet);
        $this->assertStringContainsString('<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A2" sqref="A2"/>', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A1:C3"/>', $sheet);
        $this->assertLessThan(strpos($sheet, '<sheetData>'), strpos($sheet, '<cols>'));
        $this->assertGreaterThan(strpos($sheet, '</sheetData>'), strpos($sheet, '<autoFilter'));
        $this->assertStringContainsString(
            '<definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">&apos;Data&apos;!$A$1:$C$3</definedName>',
            self::part($path, 'xl/workbook.xml'),
        );
        self::assertWellFormed($sheet);
    }

    public function test_autofilter_without_rows_is_omitted(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->sheet('Empty', new SheetOptions(autoFilter: true))->close();

        $this->assertStringNotContainsString('autoFilter', self::part($path, 'xl/worksheets/sheet1.xml'));
        $this->assertStringNotContainsString('definedName', self::part($path, 'xl/workbook.xml'));
    }

    public function test_multiple_sheets_order_and_names(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->sheet('Első')->addRow(['one'])
            ->sheet('R&D <2>')->addRow(['two'])
            ->sheet('Third')->addRow(['three'])
            ->close();

        $reader = new XlsxReader($path, new ReadOptions);

        try {
            $this->assertSame(['Első', 'R&D <2>', 'Third'], array_map(fn (SheetInfo $s): string => $s->name, $reader->sheets()));
            $this->assertSame([1 => ['one']], iterator_to_array($reader->rows('Első')));
            $this->assertSame([1 => ['two']], iterator_to_array($reader->rows('R&D <2>')));
            $this->assertSame([1 => ['three']], iterator_to_array($reader->rows(2)));
        } finally {
            $reader->close();
        }
    }

    public function test_implicit_first_sheet_is_named_sheet1(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = new XlsxWriter($path);
        $writer->addRow(['a']);

        try {
            $writer->sheet('SHEET1');
            $this->fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('SHEET1', $e->getMessage());
        }

        $writer->sheet('Other')->addRow(['b'])->close();

        $reader = new XlsxReader($path, new ReadOptions);
        $this->assertSame(['Sheet1', 'Other'], array_map(fn (SheetInfo $s): string => $s->name, $reader->sheets()));
        $reader->close();
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidSheetNames(): iterable
    {
        yield 'too long' => [[str_repeat('x', 32)], str_repeat('x', 32)];
        yield 'slash' => [['a/b'], 'a/b'];
        yield 'backslash' => [['a\\b'], 'a\\b'];
        yield 'bracket' => [['[x]'], '[x]'];
        yield 'colon' => [['a:b'], 'a:b'];
        yield 'star' => [['a*'], 'a*'];
        yield 'question' => [['a?'], 'a?'];
        yield 'empty' => [[''], '""'];
        yield 'apostrophe edge' => [["'quoted'"], "'quoted'"];
        yield 'control char' => [["a\x01b"], 'a'];
        yield 'xml-illegal U+FFFE' => [["a\u{FFFE}b"], 'cannot hold in XML'];
        yield 'xml-illegal U+FFFF' => [["\u{FFFF}"], 'cannot hold in XML'];
        yield 'duplicate ignoring case' => [['Sheet1', 'sheet1'], 'sheet1'];
        yield 'duplicate ignoring accent case' => [['Árvíz', 'árvíz'], 'árvíz'];
    }

    /**
     * @param  list<string>  $names
     */
    #[DataProvider('invalidSheetNames')]
    public function test_invalid_sheet_names_throw(array $names, string $expected): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new XlsxWriter($dir.'/out.xlsx');
            $last = array_pop($names);
            $this->assertNotNull($last);

            foreach ($names as $name) {
                $writer->sheet($name);
            }

            try {
                $writer->sheet($last);
                $this->fail('Expected exception');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }

            // Nothing is written for a rejected sheet.
            if ($names === []) {
                $this->assertSame([], glob($dir.'/*'));
            }

            // The writer stays usable after a rejected name.
            $writer->sheet('Valid')->addRow(['ok'])->close();
            $this->assertFileExists($dir.'/out.xlsx');
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_31_character_unicode_name_is_accepted(): void
    {
        $path = Fixtures::path('xlsx');
        $name = str_repeat('é', 31);
        (new XlsxWriter($path))->sheet($name)->addRow(['x'])->close();

        $reader = new XlsxReader($path, new ReadOptions);
        $this->assertSame($name, $reader->sheets()[0]->name);
        $reader->close();
    }

    public function test_output_validates(): void
    {
        $path = Fixtures::path('xlsx');
        $header = Style::make()->bold()->fill('DDEBF7')->border('808080')->align('center');
        (new XlsxWriter($path))
            ->sheet('Summary', new SheetOptions([1 => 20, 3 => 12.5], 1, true))
            ->addRow(['Name', 'Amount', 'Date'], $header)
            ->addRow(['Alice', Cell::of(1234.5, Style::make()->numberFormat('#,##0.00')), new DateTimeImmutable('2025-03-01 08:30:00')])
            ->addRow(['Bob', Cell::formula('=B2*2'), null])
            ->sheet('Details')
            ->addRow([Cell::text('wrapped text', Style::make()->wrap()->italic()->fontColor('C00000')), true, -7])
            ->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::RDONLY));
        $names = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $names[] = $name;
                self::assertWellFormed((string) $zip->getFromIndex($i), $name);
            }

            $types = self::xpath((string) $zip->getFromName('[Content_Types].xml'), 'http://schemas.openxmlformats.org/package/2006/content-types');
        } finally {
            $zip->close();
        }

        sort($names);
        $this->assertSame([
            '[Content_Types].xml', '_rels/.rels', 'docProps/app.xml', 'xl/_rels/workbook.xml.rels',
            'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml',
        ], $names);

        foreach (['/xl/worksheets/sheet1.xml', '/xl/worksheets/sheet2.xml'] as $part) {
            $this->assertSame(
                'application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml',
                self::attr($types, '/m:Types/m:Override[@PartName="'.$part.'"]', 'ContentType'),
            );
        }

        $this->assertNotSame('', self::attr($types, '/m:Types/m:Override[@PartName="/xl/workbook.xml"]', 'ContentType'));
        $this->assertNotSame('', self::attr($types, '/m:Types/m:Override[@PartName="/xl/styles.xml"]', 'ContentType'));
        $this->assertStringContainsString('fullCalcOnLoad="1"', self::part($path, 'xl/workbook.xml'));
    }

    public function test_workbook_without_formulas_skips_full_recalculation(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow([1])->close();

        $this->assertStringNotContainsString('fullCalcOnLoad', self::part($path, 'xl/workbook.xml'));
    }

    public function test_close_without_rows_creates_a_valid_empty_workbook(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->close();

        $reader = new XlsxReader($path, new ReadOptions);
        $this->assertSame('Sheet1', $reader->sheets()[0]->name);
        $this->assertSame([], iterator_to_array($reader->rows()));
        $reader->close();
    }

    public function test_numbers_round_trip_exactly(): void
    {
        $path = Fixtures::path('xlsx');
        $values = [0, -1, PHP_INT_MAX >> 12, 0.1, 0.30000000000000004, -2.5e-10, 1.5e20];
        (new XlsxWriter($path))->addRow($values)->close();

        $this->assertSame([0, -1, PHP_INT_MAX >> 12, 0.1, 0.30000000000000004, -2.5e-10, 1.5e20], self::readRows($path)[1]);
    }

    public function test_float_mapping_ignores_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'hu_HU.UTF-8', 'hu_HU', 'de_DE.UTF-8');

        try {
            $path = Fixtures::path('xlsx');
            (new XlsxWriter($path))->addRow([1234.5])->close();
        } finally {
            setlocale(LC_NUMERIC, $previous === false ? 'C' : $previous);
        }

        $this->assertStringContainsString('<v>1234.5</v>', self::part($path, 'xl/worksheets/sheet1.xml'));
    }

    public function test_non_finite_float_throws_and_aborts(): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new XlsxWriter($dir.'/out.xlsx');
            $writer->addRow(['ok']);

            try {
                $writer->addRow([NAN]);
                $this->fail('Expected exception');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('finite', $e->getMessage());
            }

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_atomic_close(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = new XlsxWriter($path);
        $writer->addRow(['a', 'b']);

        $this->assertFileDoesNotExist($path);

        $writer->close();
        $this->assertFileExists($path);
        $size = filesize($path);

        $writer->close();
        $this->assertSame($size, filesize($path));
        $this->assertSame([1 => ['a', 'b']], self::readRows($path));
    }

    public function test_exception_mid_write_leaves_no_files(): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new XlsxWriter($dir.'/out.xlsx');
            $writer->sheet('First')->addRow(['done']);
            $rows = (function (): Generator {
                yield ['ok'];

                throw new RuntimeException('boom');
            })();

            try {
                $writer->sheet('Second')->addRows($rows);
                $this->fail('Expected exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            try {
                $writer->close();
                $this->fail('Expected exception');
            } catch (LogicException $e) {
                $this->assertStringContainsString('aborted', $e->getMessage());
            }

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_write_after_close_throws(): void
    {
        $writer = new XlsxWriter(Fixtures::path('xlsx'));
        $writer->close();

        $this->expectException(LogicException::class);

        $writer->addRow(['a']);
    }

    public function test_sheet_after_close_throws(): void
    {
        $writer = new XlsxWriter(Fixtures::path('xlsx'));
        $writer->close();

        $this->expectException(LogicException::class);

        $writer->sheet('Late');
    }

    public function test_destruct_without_close_removes_temps_and_never_creates_target(): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new XlsxWriter($dir.'/out.xlsx');
            $writer->sheet('One')->addRow(['a'])->sheet('Two')->addRow(['b']);
            $this->assertCount(2, glob($dir.'/*') ?: []);

            unset($writer);

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_only_one_sheet_handle_is_open_at_a_time(): void
    {
        $writer = new XlsxWriter(Fixtures::path('xlsx'));
        $writer->sheet('One')->addRow(['a'])->sheet('Two')->addRow(['b']);

        $handles = array_filter(get_resources('stream'), fn ($h): bool => str_contains(stream_get_meta_data($h)['uri'] ?? '', 'sps_xlsx_'));
        $this->assertCount(1, $handles);

        $writer->close();
    }

    /**
     * @return iterable<string, array{callable(string): mixed}>
     */
    public static function brokenSheetParts(): iterable
    {
        // Unreadable: ZipArchive::addFile() accepts it, the deferred read in close() fails.
        yield 'unreadable when the archive is written' => [static fn (string $path): bool => chmod($path, 0)];
        // Missing: ZipArchive::addFile() itself refuses it.
        yield 'missing when added' => [static fn (string $path): bool => unlink($path)];
    }

    /**
     * @param  callable(string): mixed  $break
     */
    #[DataProvider('brokenSheetParts')]
    public function test_zip_assembly_failure_throws_and_cleans_up(callable $break): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new XlsxWriter($dir.'/out.xlsx');
            $writer->sheet('One')->addRow(['a'])->sheet('Two')->addRow(['b']);

            $finished = new ReflectionProperty(XlsxWriter::class, 'sheetPaths');
            /** @var list<string> $paths */
            $paths = $finished->getValue($writer);
            $break($paths[0]);

            try {
                $writer->close();
                $this->fail('Expected exception');
            } catch (SpreadsheetException $e) {
                $this->assertStringContainsString('out.xlsx', $e->getMessage());
            }

            $this->assertSame([], glob($dir.'/*'));
            try {
                $writer->close();
                $this->fail('Expected exception');
            } catch (LogicException $e) {
                $this->assertStringContainsString('aborted', $e->getMessage());
            }
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_missing_target_directory_throws_naming_it(): void
    {
        $writer = new XlsxWriter('/nonexistent-sps-dir/out.xlsx');

        $this->expectException(SpreadsheetException::class);
        $this->expectExceptionMessage('/nonexistent-sps-dir');

        $writer->addRow(['a']);
    }

    public function test_final_file_has_umask_permissions(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow(['a'])->close();

        clearstatcache();
        $this->assertSame(0666 & ~umask(), fileperms($path) & 0777);
    }

    public function test_memory_flat(): void
    {
        $small = $this->writeRows(30_000);
        $large = $this->writeRows(300_000);

        $this->assertLessThan(8 * 1024 * 1024, $large - $small);
    }

    public function test_close_after_abort_throws_and_writes_nothing(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = new XlsxWriter($path);

        try {
            $writer->addRow([NAN]);
            $this->fail('Expected exception');
        } catch (InvalidArgumentException) {
        }

        try {
            $writer->close();
            $this->fail('Expected exception');
        } catch (LogicException $e) {
            $this->assertStringContainsString('aborted', $e->getMessage());
            $this->assertStringContainsString('no file was written', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_float_text_is_shortest_round_trip_regardless_of_ini_precision(): void
    {
        $path = Fixtures::path('xlsx');
        $serialize = ini_get('serialize_precision');
        $precision = ini_get('precision');
        ini_set('serialize_precision', '5');
        ini_set('precision', '3');

        try {
            (new XlsxWriter($path))->addRow([-0.0, 1.0E+25, 1e-7, 0.1 + 0.2, PHP_FLOAT_MAX, 5e-324, 1e16, 2.5])->close();
        } finally {
            ini_set('serialize_precision', (string) $serialize);
            ini_set('precision', (string) $precision);
        }

        preg_match_all('#<v>([^<]*)</v>#', self::part($path, 'xl/worksheets/sheet1.xml'), $m);
        $this->assertSame(['0', '1.0E+25', '1.0E-7', '0.30000000000000004', '1.7976931348623157E+308', '5.0E-324', '10000000000000000', '2.5'], $m[1]);
        // A negative zero reads back as zero; everything else round-trips bit for bit.
        $this->assertSame([0, 1.0E+25, 1e-7, 0.1 + 0.2, PHP_FLOAT_MAX, 5e-324, 1e16, 2.5], self::readRows($path)[1]);
    }

    private function writeRows(int $count): int
    {
        $path = Fixtures::path('xlsx');
        $style = Style::make()->bold()->fill('DDEBF7');
        $date = new DateTimeImmutable('2025-01-31 10:00:00');

        $growth = self::peakGrowth(function () use ($path, $count, $style, $date): void {
            $writer = new XlsxWriter($path);

            for ($i = 0; $i < $count; $i++) {
                $writer->addRow([$i, 'name '.$i, $i * 1.5, true, $date, 'x', null, -$i, 'árvíztűrő', 0.5], $style);
            }

            $writer->close();
        });

        $this->assertFileExists($path);

        return $growth;
    }

    /**
     * @return array<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private static function readRows(string $path): array
    {
        $reader = new XlsxReader($path, new ReadOptions);

        try {
            return iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_date_columns_are_widened_to_fit(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->sheet('Data', new SheetOptions(columnWidths: [5 => 6]))
            ->addRow(['name', 'date', 'stamp', 'named', 'narrow', 'number'])
            ->addRow([
                'a',
                new DateTimeImmutable('2026-01-15'),
                new DateTimeImmutable('2026-01-15 13:45:00'),
                Cell::of(new DateTimeImmutable('2026-09-30'), Style::make()->numberFormat('mmmm d, yyyy')),
                new DateTimeImmutable('2026-01-15'),
                12,
            ])
            ->close();

        $sheet = self::part($path, 'xl/worksheets/sheet1.xml');

        // Dates get the width of their longest display; an explicit width always wins.
        $this->assertSame([
            [2, 2, '11.7109375'],
            [3, 4, '20.7109375'],
            [5, 5, '6.7109375'],
        ], self::columns($sheet));
        $rows = self::sheetRows($path);
        $this->assertSame('a', $rows[2][0]);
        $this->assertEquals(new DateTimeImmutable('2026-01-15'), $rows[2][1]);
    }

    public function test_neighbouring_date_columns_share_one_col_element(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->addRow([new DateTimeImmutable('2026-01-15'), new DateTimeImmutable('2026-01-16'), 'x', new DateTimeImmutable('2026-01-17')])
            ->close();

        $this->assertSame([[1, 2, '11.7109375'], [4, 4, '11.7109375']], self::columns(self::part($path, 'xl/worksheets/sheet1.xml')));
    }

    public function test_sheet_without_widths_or_dates_has_no_cols(): void
    {
        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))->addRow(['a', 1, true])->close();

        $sheet = self::part($path, 'xl/worksheets/sheet1.xml');

        $this->assertStringNotContainsString('<cols>', $sheet);
        $this->assertWellFormedXml($sheet);
    }

    public function test_many_date_columns_overflow_the_reserved_room(): void
    {
        // Alternating widths cannot merge, so the cols element outgrows the space reserved for it
        // and the sheet file is rewritten.
        $row = [];

        for ($column = 0; $column < 400; $column++) {
            $row[] = $column % 2 === 0 ? new DateTimeImmutable('2026-01-15') : new DateTimeImmutable('2026-01-15 10:00:00');
        }

        $path = Fixtures::path('xlsx');
        (new XlsxWriter($path))
            ->sheet('First')->addRow($row)->addRow(['tail'])
            ->sheet('Second')->addRow([new DateTimeImmutable('2026-01-15')])
            ->close();

        $first = self::part($path, 'xl/worksheets/sheet1.xml');
        $this->assertWellFormedXml($first);
        $columns = self::columns($first);
        $this->assertCount(400, $columns);
        $this->assertSame([400, 400, '20.7109375'], $columns[399]);
        $this->assertSame([[1, 1, '11.7109375']], self::columns(self::part($path, 'xl/worksheets/sheet2.xml')));

        $rows = self::sheetRows($path);
        $this->assertCount(400, $rows[1]);
        $this->assertSame(['tail'], $rows[2]);
    }

    /**
     * @return list<array{int, int, string}> min, max and width of every col element
     */
    private static function columns(string $sheet): array
    {
        preg_match_all('/<col min="(\d+)" max="(\d+)" width="([^"]+)" customWidth="1"\/>/', $sheet, $matches, PREG_SET_ORDER);

        return array_map(static fn (array $match): array => [(int) $match[1], (int) $match[2], $match[3]], $matches);
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private static function sheetRows(string $path): array
    {
        $reader = new XlsxReader($path, new ReadOptions);

        try {
            return iterator_to_array($reader->rows(0));
        } finally {
            $reader->close();
        }
    }

    private function assertWellFormedXml(string $xml): void
    {
        $document = new DOMDocument;
        $this->assertTrue(@$document->loadXML($xml), 'The sheet XML is not well-formed.');
    }

    private static function part(string $path, string $name): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open '.$path);
        }

        try {
            $contents = $zip->getFromName($name);
        } finally {
            $zip->close();
        }

        if ($contents === false) {
            throw new RuntimeException('Missing part '.$name);
        }

        return $contents;
    }

    private static function numberFormatOf(string $path, string $cell): string
    {
        $sheet = self::xpath(self::part($path, 'xl/worksheets/sheet1.xml'));
        $styles = self::xpath(self::part($path, 'xl/styles.xml'));
        $xf = self::nth($styles, '/m:styleSheet/m:cellXfs/m:xf', (int) self::attr($sheet, '//m:c[@r="'.$cell.'"]', 's'));
        $id = $xf->getAttribute('numFmtId');
        self::assertSame('1', $xf->getAttribute('applyNumberFormat'));

        return self::attr($styles, '/m:styleSheet/m:numFmts/m:numFmt[@numFmtId="'.$id.'"]', 'formatCode');
    }

    private static function xpath(string $xml, string $namespace = self::MAIN_NS): DOMXPath
    {
        $document = new DOMDocument;
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('m', $namespace);

        return $xpath;
    }

    /**
     * @return DOMNodeList<\DOMNode|\DOMNameSpaceNode>
     */
    private static function query(DOMXPath $xpath, string $query, ?DOMElement $context = null): DOMNodeList
    {
        $nodes = $xpath->query($query, $context);
        self::assertNotFalse($nodes, $query);

        return $nodes;
    }

    private static function nth(DOMXPath $xpath, string $query, int $index): DOMElement
    {
        $nodes = $xpath->query($query);
        $node = $nodes === false ? null : $nodes->item($index);
        self::assertInstanceOf(DOMElement::class, $node, $query.'['.$index.']');

        return $node;
    }

    private static function attr(DOMXPath $xpath, string $query, string $attribute, ?DOMElement $context = null): string
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node->getAttribute($attribute);
    }

    private static function assertWellFormed(string $xml, string $name = 'part'): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $ok = $document->loadXML($xml);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        self::assertTrue($ok && $errors === [], $name.' is not well-formed XML');
    }

    private static function emptyDirectory(): string
    {
        $dir = sys_get_temp_dir().'/sps_xw_'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    private static function removeDirectory(string $dir): void
    {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}
