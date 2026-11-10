<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Xlsx;

use DateTimeImmutable;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use IntrAgile\Spreadsheet\Tests\Support\XlsxFixtureBuilder;
use IntrAgile\Spreadsheet\Xlsx\XlsxReader;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class XlsxReaderTest extends TestCase
{
    public function test_types(): void
    {
        $rows = $this->read('excel-mixed.xlsx');

        $this->assertSame(['Egész', 'Tört', 'Szöveg', 'Logikai', 'Dátum', 'Üres', 'Utolsó'], $rows[1]);
        $this->assertSame(42, $rows[2][0]);
        $this->assertSame(3.14, $rows[2][1]);
        $this->assertSame('szöveg', $rows[2][2]);
        $this->assertTrue($rows[2][3]);
        $this->assertEquals(new DateTimeImmutable('2025-01-31'), $rows[2][4]);
        $this->assertNull($rows[2][5]);
        $this->assertSame('utolsó', $rows[2][6]);
        $this->assertSame(
            [42, 3.14, 'szöveg', true, 'date:2025-01-31 00:00:00', null, 'utolsó'],
            self::normalize($rows[2]),
        );
    }

    public function test_formula_results_errors_and_booleans(): void
    {
        $rows = $this->read('excel-mixed.xlsx');

        $this->assertSame([84, 'szöveg!', '#N/A', false], $rows[4]);
    }

    public function test_numbers_are_int_only_when_integral_and_exact(): void
    {
        $rows = $this->read('excel-mixed.xlsx');

        $this->assertSame([-7, 1.0E20, 0.001, 9007199254740992.0], $rows[7]);
    }

    public function test_custom_date_formats(): void
    {
        $rows = $this->read('excel-mixed.xlsx');

        $this->assertSame(['date:2025-01-31 00:00:00', 'date:2025-01-31 00:00:00', 0.125, 1500], self::normalize($rows[3]));
    }

    public function test_1904_workbook(): void
    {
        $rows = $this->read('excel-1904.xlsx');

        $this->assertEquals(new DateTimeImmutable('2025-01-31'), $rows[2][0]);
        $this->assertSame(['date:2025-01-31 12:00:00'], self::normalize($rows[3]));
    }

    public function test_missing_r_attributes_use_order(): void
    {
        $rows = $this->read('no-r-attributes.xlsx');

        $this->assertSame([1 => ['a', 'b', 'c'], 2 => [1, null, 3], 4 => ['d']], $rows);
    }

    public function test_gaps_filled_with_null(): void
    {
        $rows = $this->read('excel-mixed.xlsx');

        $this->assertSame([null, null, 'x'], $rows[5]);
    }

    public function test_fully_empty_rows_are_skipped(): void
    {
        $rows = $this->read('excel-mixed.xlsx');

        $this->assertSame([1, 2, 3, 4, 5, 7], array_keys($rows));
    }

    public function test_inline_strings_and_rich_text_runs_concatenated(): void
    {
        $rows = $this->read('inline-strings.xlsx');

        $this->assertSame([
            1 => ['sima', 'Félkövér szöveg', 'Közös szöveg'],
            2 => ['  szóközök  ', "első sor\nmásodik sor", '<&> "idézet"', "sor1\rsor2 _x000D_"],
        ], $rows);
    }

    public function test_sheet_selection_by_name_and_index_and_unknown_throws(): void
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/multi-sheet.xlsx'), new ReadOptions);

        $this->assertSame([1 => ['első']], iterator_to_array($reader->rows()));
        $this->assertSame([1 => ['rejtett']], iterator_to_array($reader->rows(1)));
        $this->assertSame([1 => ['nagyon rejtett']], iterator_to_array($reader->rows('Nagyon rejtett')));
        $this->assertSame([1 => ['utolsó']], iterator_to_array($reader->rows('Utolsó')));

        try {
            $reader->rows('Nincs');
            $this->fail('An unknown sheet name must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Nincs', $exception->getMessage());
            $this->assertStringContainsString('"Első", "Rejtett", "Nagyon rejtett", "Utolsó"', $exception->getMessage());
        }

        try {
            $reader->rows(4);
            $this->fail('An unknown sheet index must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Első', $exception->getMessage());
        }

        $reader->close();
    }

    public function test_sheets_lists_names_in_workbook_order_with_visibility(): void
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/multi-sheet.xlsx'), new ReadOptions);

        $this->assertEquals([
            new SheetInfo('Első', 0, true),
            new SheetInfo('Rejtett', 1, false),
            new SheetInfo('Nagyon rejtett', 2, false),
            new SheetInfo('Utolsó', 3, true),
        ], $reader->sheets());

        $reader->close();
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function producers(): iterable
    {
        yield 'LibreOffice' => ['libreoffice.xlsx'];
        yield 'Google Sheets' => ['google-sheets.xlsx'];
    }

    #[DataProvider('producers')]
    public function test_files_from_libreoffice_and_google_sheets_read_identically(string $fixture): void
    {
        $rows = array_map(self::normalize(...), $this->read($fixture));

        $this->assertSame([
            1 => ['Név', 'Kor', 'Születés', 'Aktív'],
            2 => ['Anna', 34, 'date:1990-05-17 00:00:00', true],
            3 => ['Béla', 27.5, 'date:2001-12-03 00:00:00', false],
        ], $rows);
    }

    public function test_rows_with_header(): void
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/google-sheets.xlsx'), new ReadOptions);
        $rows = iterator_to_array($reader->rowsWithHeader());
        $reader->close();

        $this->assertSame([2, 3], array_keys($rows));
        $this->assertSame('Anna', $rows[2]['Név']);
        $this->assertFalse($rows[3]['Aktív']);
    }

    public function test_rows_can_be_iterated_again_and_side_by_side(): void
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions);
        $first = $reader->rows();
        $second = $reader->rows();

        $this->assertSame($first->current(), $second->current());
        $first->next();
        $this->assertSame(42, $first->current()[0]);
        $this->assertSame(['Egész', 'Tört', 'Szöveg', 'Logikai', 'Dátum', 'Üres', 'Utolsó'], $second->current());
        $this->assertCount(6, iterator_to_array($reader->rows()));

        $reader->close();
    }

    public function test_close_releases_open_generators(): void
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions(sharedStringsInMemory: 0));
        $rows = $reader->rows();
        $this->assertSame('Egész', $rows->current()[0]);

        $reader->close();
        $reader->close();

        // The reader stays usable: a new pass reopens the archive and reloads the strings.
        $this->assertSame('Egész', $reader->rows()->current()[0]);
        $reader->close();
    }

    public function test_not_a_zip_is_corrupt(): void
    {
        $this->expectException(CorruptFile::class);

        new XlsxReader(Fixtures::file('not a zip', 'xlsx'), new ReadOptions);
    }

    public function test_zip_without_workbook_is_corrupt(): void
    {
        $this->expectException(CorruptFile::class);

        new XlsxReader(Fixtures::zip(['hello.txt' => 'hi'], 'xlsx'), new ReadOptions);
    }

    public function test_entry_limit_applies(): void
    {
        $this->expectException(LimitExceeded::class);

        new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions(maxZipEntries: 3));
    }

    public function test_row_number_above_the_spreadsheet_maximum_throws_limit_exceeded(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"><v>1</v></c></row><row r="1048577"><c r="A1048577"><v>2</v></c></row></sheetData></worksheet>',
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);

        $this->expectException(LimitExceeded::class);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_malformed_sheet_is_corrupt(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1"><v>1</v></c></row><row r="2"><c r="A2"><v>2</v>',
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);
        $seen = [];

        try {
            foreach ($reader->rows() as $number => $row) {
                $seen[$number] = $row;
            }

            $this->fail('A truncated sheet must throw.');
        } catch (CorruptFile) {
            $this->assertSame([1 => [1]], $seen);
        } finally {
            $reader->close();
        }
    }

    public function test_truncated_shared_strings_are_corrupt(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => str_replace('</Relationships>', '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', self::workbookRels()),
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><si><t>a</t></si><si><t>b',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="s"><v>0</v></c></row></sheetData></worksheet>',
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);

        $this->expectException(CorruptFile::class);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_doctype_with_internal_subset_is_refused(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><!DOCTYPE worksheet [<!ENTITY x "boom">]>'
                .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>&x;</t></is></c></row></sheetData></worksheet>',
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);

        $this->expectException(CorruptFile::class);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_cell_reference_beyond_last_column_is_corrupt(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1"><c r="ZZZZZZ1"><v>1</v></c></row></sheetData></worksheet>',
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);

        $this->expectException(CorruptFile::class);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_cells_without_reference_beyond_last_column_are_refused(): void
    {
        $path = self::singleSheet('<row r="1">'.str_repeat('<c><v>1</v></c>', 16_385).'</row>');

        self::withoutPhpErrors(function () use ($path): void {
            $reader = new XlsxReader($path, new ReadOptions);

            try {
                iterator_to_array($reader->rows());
                $this->fail('A cell beyond column XFD was accepted.');
            } catch (LimitExceeded $exception) {
                $this->assertStringContainsString('16384 columns', $exception->getMessage());
            } finally {
                $reader->close();
            }
        });
    }

    public function test_cells_without_reference_up_to_last_column_are_read(): void
    {
        $rows = iterator_to_array((new XlsxReader(self::singleSheet('<row r="1">'.str_repeat('<c><v>1</v></c>', 16_384).'</row>'), new ReadOptions))->rows());

        $this->assertCount(16_384, $rows[1]);
    }

    public function test_shared_string_beyond_the_cell_limit_is_refused(): void
    {
        $path = self::withSharedStrings('<si><r><t>abcdef</t></r><r><t>ghijk</t></r></si>', '<row r="1"><c r="A1" t="s"><v>0</v></c></row>');

        $this->assertSame([1 => ['abcdefghijk']], iterator_to_array((new XlsxReader($path, new ReadOptions(maxCellBytes: 11)))->rows()));

        self::withoutPhpErrors(function () use ($path): void {
            $this->expectException(LimitExceeded::class);

            iterator_to_array((new XlsxReader($path, new ReadOptions(maxCellBytes: 10)))->rows());
        });
    }

    public function test_inline_string_beyond_the_cell_limit_is_refused(): void
    {
        $path = self::singleSheet('<row r="1"><c r="A1" t="inlineStr"><is><t>abcdefghijk</t></is></c></row>');

        $this->assertSame([1 => ['abcdefghijk']], iterator_to_array((new XlsxReader($path, new ReadOptions(maxCellBytes: 11)))->rows()));

        $this->expectException(LimitExceeded::class);

        iterator_to_array((new XlsxReader($path, new ReadOptions(maxCellBytes: 10)))->rows());
    }

    public function test_formula_string_results_are_unescaped_but_errors_stay_raw(): void
    {
        $path = self::singleSheet('<row r="1">'
            .'<c r="A1" t="str"><f>A2&amp;"x"</f><v>line1_x000D_line2 _x005F_x000D_</v></c>'
            .'<c r="B1" t="e"><v>#N/A_x000D_</v></c>'
            .'</row>');

        $this->assertSame([1 => ["line1\rline2 _x000D_", '#N/A_x000D_']], iterator_to_array((new XlsxReader($path, new ReadOptions))->rows()));
    }

    public function test_non_numeric_untyped_value_beyond_the_cell_limit_is_refused(): void
    {
        $path = self::singleSheet('<row r="1"><c r="A1"><v>abcdefghijk</v></c><c r="B1" t="n"><v>lmnopqrstuv</v></c></row>');

        $this->assertSame([1 => ['abcdefghijk', 'lmnopqrstuv']], iterator_to_array((new XlsxReader($path, new ReadOptions(maxCellBytes: 11)))->rows()));

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessage('A cell holds more than 10 bytes of text.');

        iterator_to_array((new XlsxReader($path, new ReadOptions(maxCellBytes: 10)))->rows());
    }

    public function test_shared_string_count_is_limited(): void
    {
        $path = self::withSharedStrings(str_repeat('<si><t>a</t></si>', 4), '<row r="1"><c r="A1" t="s"><v>0</v></c></row>');

        $this->assertCount(1, iterator_to_array((new XlsxReader($path, new ReadOptions(maxSharedStrings: 4)))->rows()));

        self::withoutPhpErrors(function () use ($path): void {
            $this->expectException(LimitExceeded::class);
            $this->expectExceptionMessage('3 entries');

            iterator_to_array((new XlsxReader($path, new ReadOptions(maxSharedStrings: 3)))->rows());
        });
    }

    public function test_more_than_4096_sheets_are_refused(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheets>'
                .str_repeat('<sheet name="s"/>', 4097).'</sheets></workbook>',
        ], 'xlsx');

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessage('4096 sheets');

        new XlsxReader($path, new ReadOptions);
    }

    public function test_more_than_65536_cell_formats_are_refused(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs>'
                .str_repeat('<xf numFmtId="14"/>', 65_537).'</cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData/></worksheet>',
        ], 'xlsx');

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessage('65536 cell formats');

        // The repetitive part compresses far beyond the default ratio limit, which would hide the cap.
        new XlsxReader($path, new ReadOptions(maxCompressionRatio: 1_000_000));
    }

    public function test_date_styled_serials_outside_the_calendar_stay_numbers(): void
    {
        $cells = '';

        foreach (['1e15', '1e300', '1e400', '-1', '2958466', '2958465.99999', '45658.5', '0'] as $column => $serial) {
            $cells .= '<c r="'.chr(65 + $column).'1" s="1"><v>'.$serial.'</v></c>';
        }

        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><cellXfs><xf numFmtId="0"/><xf numFmtId="14"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData><row r="1">'.$cells.'</row></sheetData></worksheet>',
        ], 'xlsx');

        $row = self::withoutPhpErrors(static fn (): array => iterator_to_array((new XlsxReader($path, new ReadOptions))->rows())[1]);

        $this->assertSame(1_000_000_000_000_000, $row[0]);
        $this->assertSame(1e300, $row[1]);
        $this->assertSame(INF, $row[2]);
        $this->assertSame(-1, $row[3]);
        $this->assertSame(2958466, $row[4]);
        $this->assertSame(['date:9999-12-31 23:59:59', 'date:2025-01-01 12:00:00', 'date:1899-12-31 00:00:00'], self::normalize(array_slice($row, 5)));
    }

    public function test_pretty_printed_sheet_with_absolute_targets(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => str_replace('Target="xl/workbook.xml"', 'Target="/xl/workbook.xml"', self::packageRels()),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => str_replace('Target="worksheets/sheet1.xml"', 'Target="/xl/worksheets/sheet1.xml"', self::workbookRels()),
            'xl/worksheets/sheet1.xml' => "<worksheet xmlns=\"http://schemas.openxmlformats.org/spreadsheetml/2006/main\">\n <sheetData>\n  <row r=\"1\">\n"
                ."   <c r=\"A1\">\n    <v>1</v>\n   </c>\n"
                ."   <c r=\"B1\"></c>\n"
                ."   <c r=\"C1\" t=\"str\">\n    <f>X</f>\n    <v>y</v>\n   </c>\n"
                ."   <c r=\"D1\"><f>Z</f></c>\n"
                ."   <c r=\"E1\" t=\"inlineStr\">\n    <is>\n     <t>in</t>\n    </is>\n   </c>\n"
                ."  </row>\n </sheetData>\n</worksheet>",
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);
        $rows = iterator_to_array($reader->rows());
        $reader->close();

        $this->assertSame([1 => [1, null, 'y', null, 'in']], $rows);
    }

    public function test_iso_date_cells(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                .'<row r="1"><c r="A1" t="d"><v>2025-01-31T13:45:00Z</v></c><c r="B1" t="d"><v>2025-01-31</v></c>'
                .'<c r="C1" t="d"><v>2025-01-31T08:05</v></c><c r="D1" t="d"><v>2025-01-31T23:59:58.25+02:00</v></c></row>'
                .'</sheetData></worksheet>',
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);
        $rows = iterator_to_array($reader->rows());
        $reader->close();

        $this->assertSame(
            [1 => ['date:2025-01-31 13:45:00', 'date:2025-01-31 00:00:00', 'date:2025-01-31 08:05:00', 'date:2025-01-31 23:59:58']],
            array_map(self::normalize(...), $rows),
        );
        $this->assertInstanceOf(DateTimeImmutable::class, $rows[1][3]);
        $this->assertSame('250000', $rows[1][3]->format('u'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidIsoDates(): iterable
    {
        yield 'relative word' => ['now'];
        yield 'relative day' => ['tomorrow'];
        yield 'free text' => ['nonsense'];
        yield 'impossible day' => ['2025-02-30'];
        yield 'impossible hour' => ['2025-01-31T24:00:00'];
        yield 'space separator' => ['2025-01-31 13:45:00'];
    }

    #[DataProvider('invalidIsoDates')]
    public function test_iso_date_cells_are_parsed_strictly(string $value): void
    {
        $path = self::singleSheet('<row r="1"><c r="A1" t="d"><v>'.$value.'</v></c></row>');
        $reader = new XlsxReader($path, new ReadOptions);

        $this->expectException(CorruptFile::class);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_sheet_whose_part_is_missing_is_corrupt(): void
    {
        $path = Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
        ], 'xlsx');
        $reader = new XlsxReader($path, new ReadOptions);

        $this->assertEquals([new SheetInfo('Sheet1', 0)], $reader->sheets());

        try {
            $reader->rows();
            $this->fail('A sheet without its part must throw.');
        } catch (CorruptFile $exception) {
            $this->assertStringContainsString('Sheet1', $exception->getMessage());
        } finally {
            $reader->close();
        }
    }

    public function test_resuming_rows_after_close_throws(): void
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions(sharedStringsInMemory: 0));
        $rows = $reader->rows();
        $this->assertSame('Egész', $rows->current()[0]);
        $reader->close();

        $this->expectException(LogicException::class);

        $rows->next();
    }

    public function test_truncated_sheet_fails_when_generators_interleave(): void
    {
        $modeBefore = libxml_use_internal_errors();
        $good = new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions);
        $bad = new XlsxReader(self::truncatedSheet(2000), new ReadOptions);

        // The good reader opens first and finishes first, while the bad one is suspended.
        $first = $good->rows();
        $first->current();
        $second = $bad->rows();
        $second->current();
        $this->assertCount(6, iterator_to_array($first));

        $count = 0;

        try {
            foreach ($second as $row) {
                $count++;
            }

            $this->fail('A truncated sheet must throw.');
        } catch (CorruptFile) {
            $this->assertLessThanOrEqual(2000, $count);
        } finally {
            $good->close();
            $bad->close();
        }

        $this->assertSame($modeBefore, libxml_use_internal_errors());
    }

    public function test_valid_file_stays_valid_beside_a_corrupt_one(): void
    {
        $good = new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions);
        $bad = new XlsxReader(self::truncatedSheet(2000), new ReadOptions);
        $expected = array_map(self::normalize(...), $this->read('excel-mixed.xlsx'));

        $first = $good->rows();
        $collected = [$first->key() => self::normalize($first->current())];

        // The corrupt sheet fails while the good one is suspended mid-sheet.
        try {
            iterator_to_array($bad->rows());
            $this->fail('A truncated sheet must throw.');
        } catch (CorruptFile) {
            // expected
        }

        // And again with the corrupt generator suspended while the good one finishes.
        $second = $bad->rows();
        $second->current();

        for ($first->next(); $first->valid(); $first->next()) {
            $collected[$first->key()] = self::normalize($first->current());
        }

        $this->assertSame($expected, $collected);

        try {
            iterator_to_array($second);
            $this->fail('A truncated sheet must throw.');
        } catch (CorruptFile) {
            // expected
        } finally {
            $good->close();
            $bad->close();
        }
    }

    public function test_sheet_failing_mid_row_leaves_no_error_behind_for_a_suspended_one(): void
    {
        $good = new XlsxReader(Fixtures::committed('xlsx/excel-mixed.xlsx'), new ReadOptions);
        // libxml parses ahead and records the truncation before the bad reference is reached.
        $bad = new XlsxReader(self::singleSheet('<row r="1"><c r="A1"><v>1</v></c><c r="1A"><v>2</v></c></row><row r="2"><c', false), new ReadOptions);
        $expected = array_map(self::normalize(...), $this->read('excel-mixed.xlsx'));

        $first = $good->rows();
        $collected = [$first->key() => self::normalize($first->current())];

        try {
            iterator_to_array($bad->rows());
            $this->fail('A bad cell reference must throw.');
        } catch (CorruptFile $exception) {
            $this->assertSame('The cell reference "1A" is not valid.', $exception->getMessage());
        }

        for ($first->next(); $first->valid(); $first->next()) {
            $collected[$first->key()] = self::normalize($first->current());
        }

        $this->assertSame($expected, $collected);
        $good->close();
        $bad->close();
    }

    private static function truncatedSheet(int $rows): string
    {
        $data = '';

        for ($i = 1; $i <= $rows; $i++) {
            $data .= '<row r="'.$i.'"><c r="A'.$i.'"><v>'.$i.'</v></c></row>';
        }

        return self::singleSheet($data.'<row r="'.($rows + 1).'"><c r="A'.($rows + 1).'"><v>2', false);
    }

    private static function withSharedStrings(string $items, string $sheetData): string
    {
        return Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => str_replace('</Relationships>', '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>', self::workbookRels()),
            'xl/sharedStrings.xml' => '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$items.'</sst>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$sheetData.'</sheetData></worksheet>',
        ], 'xlsx');
    }

    private static function singleSheet(string $sheetData, bool $close = true): string
    {
        return Fixtures::zip([
            '_rels/.rels' => self::packageRels(),
            'xl/workbook.xml' => self::workbook(),
            'xl/_rels/workbook.xml.rels' => self::workbookRels(),
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
                .$sheetData.($close ? '</sheetData></worksheet>' : ''),
        ], 'xlsx');
    }

    public function test_memory_flat(): void
    {
        $small = $this->buildLarge(30_000);
        $large = $this->buildLarge(300_000);
        $options = new ReadOptions(sharedStringsInMemory: 10_000);

        $smallGrowth = self::peakGrowth(fn () => $this->consume($small, $options, 30_000));
        $largeGrowth = self::peakGrowth(fn () => $this->consume($large, $options, 300_000));

        $this->assertLessThan(16 * 1024 * 1024, $largeGrowth - $smallGrowth);
    }

    private function consume(string $path, ReadOptions $options, int $expectedRows): void
    {
        $reader = new XlsxReader($path, $options);
        $count = 0;
        $last = [];

        foreach ($reader->rows() as $row) {
            $count++;
            $last = $row;
        }

        $reader->close();

        $this->assertSame($expectedRows, $count);
        $this->assertSame($expectedRows, $last[0]);
        $this->assertSame("unique {$expectedRows} c", $last[7]);
        $this->assertSame('pool '.($expectedRows % 1000), $last[8]);
    }

    private function buildLarge(int $rows): string
    {
        $builder = new XlsxFixtureBuilder(Fixtures::path('xlsx'));
        $date = new DateTimeImmutable('2025-01-31');

        for ($i = 1; $i <= $rows; $i++) {
            $pool = 'pool '.($i % 1000);
            $builder->addRow([$i, $i * 1.5, $date, "unique {$i} a", $pool, "unique {$i} b", $pool, "unique {$i} c", $pool, $i % 2 === 0]);
        }

        return $builder->close();
    }

    /**
     * @return array<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private function read(string $fixture, ?ReadOptions $options = null): array
    {
        $reader = new XlsxReader(Fixtures::committed('xlsx/'.$fixture), $options ?? new ReadOptions);

        try {
            return iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<int|float|string|bool|DateTimeImmutable|null>  $row
     * @return list<int|float|string|bool|null>
     */
    private static function normalize(array $row): array
    {
        return array_map(
            static fn (int|float|string|bool|DateTimeImmutable|null $value): int|float|string|bool|null => $value instanceof DateTimeImmutable
                ? 'date:'.$value->format('Y-m-d H:i:s')
                : $value,
            $row,
        );
    }

    private static function packageRels(): string
    {
        return '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    }

    private static function workbook(): string
    {
        return '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    }

    private static function workbookRels(): string
    {
        return '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>';
    }
}
