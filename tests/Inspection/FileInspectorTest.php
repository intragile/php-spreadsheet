<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Inspection;

use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\Inspection\FileInspector;
use IntrAgile\Spreadsheet\Inspection\SheetInspection;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class FileInspectorTest extends TestCase
{
    /**
     * @param  list<list<int|float|string|null>>  $rows
     */
    private static function csv(array $rows): string
    {
        $lines = '';

        foreach ($rows as $row) {
            $lines .= implode(',', array_map(static fn ($v): string => '"'.str_replace('"', '""', (string) $v).'"', $row))."\n";
        }

        $path = Fixtures::file($lines, 'csv');

        return $path;
    }

    /**
     * @param  list<list<int|float|string|null>>  $rows
     */
    private static function firstSheet(array $rows): SheetInspection
    {
        return FileInspector::inspect(self::csv($rows))->sheets[0];
    }

    public function test_header_row_is_first_row_with_two_text_cells_followed_by_data(): void
    {
        $sheet = self::firstSheet([['Report 2026'], [], ['Name', 'Email', 'Age'], ['Ann', 'a@x.hu', 30]]);

        $this->assertSame(3, $sheet->headerRow);
        $this->assertSame(3, $sheet->columnCount);
        $this->assertTrue($sheet->hasData);
        $this->assertCount(3, $sheet->sampleRows);
    }

    public function test_numeric_strings_do_not_count_as_header(): void
    {
        $sheet = self::firstSheet([['2024', '2025'], ['1', '2']]);

        $this->assertNull($sheet->headerRow);
    }

    public function test_header_needs_a_following_non_empty_row(): void
    {
        $this->assertNull(self::firstSheet([['Name', 'Email']])->headerRow);
        $this->assertNull(self::firstSheet([['Name', 'Email'], [' ', '']])->headerRow);
        $this->assertSame(1, self::firstSheet([['Name', 'Email'], [], ['Ann', 'a@x.hu']])->headerRow);
    }

    public function test_no_candidate_gives_null(): void
    {
        $sheet = self::firstSheet([['Only'], ['One', '5'], [1, 2]]);

        $this->assertNull($sheet->headerRow);
        $this->assertTrue($sheet->hasData);
    }

    public function test_empty_file_has_no_data(): void
    {
        $inspection = FileInspector::inspect(Fixtures::file('', 'csv'), format: Format::Csv);

        $this->assertFalse($inspection->sheets[0]->hasData);
        $this->assertSame([], $inspection->sheets[0]->sampleRows);
        $this->assertNull($inspection->firstDataSheet);
    }

    public function test_format_is_reported(): void
    {
        $this->assertSame(Format::Csv, FileInspector::inspect(self::csv([['a', 'b']]))->format);
    }

    public function test_first_data_sheet_skips_empty_and_hidden_sheets(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = Spreadsheet::write($path);
        $writer->sheet('Empty');
        $writer->sheet('Data')->addRow(['Name', 'Qty'])->addRow(['Nut', 3]);
        $writer->close();

        $inspection = FileInspector::inspect($path);

        $this->assertSame(Format::Xlsx, $inspection->format);
        $this->assertCount(2, $inspection->sheets);
        $this->assertFalse($inspection->sheets[0]->hasData);
        $this->assertSame(1, $inspection->firstDataSheet);
        $this->assertSame(1, $inspection->sheets[1]->headerRow);
    }

    public function test_hidden_sheets_are_skipped_only_when_a_visible_one_has_data(): void
    {
        $hiddenFirst = self::workbook([['Hidden', 'hidden', 'secret'], ['Shown', 'visible', 'public']]);
        $this->assertSame(1, FileInspector::inspect($hiddenFirst)->firstDataSheet);

        $onlyHidden = self::workbook([['Hidden', 'hidden', 'secret'], ['Shown', 'visible', null]]);
        $inspection = FileInspector::inspect($onlyHidden);
        $this->assertFalse($inspection->sheets[0]->info->visible);
        $this->assertSame(0, $inspection->firstDataSheet);
    }

    /**
     * Builds an XLSX with inline-string sheets: name, state, and one text cell (null leaves the sheet empty).
     *
     * @param  list<array{string, string, string|null}>  $sheets
     */
    private static function workbook(array $sheets): string
    {
        $main = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $rel = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="'.$rel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        ];
        $workbook = '';
        $rels = '';

        foreach ($sheets as $i => [$name, $state, $text]) {
            $n = $i + 1;
            $workbook .= '<sheet name="'.$name.'" sheetId="'.$n.'" state="'.$state.'" r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="'.$rel.'/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $cells = $text === null ? '' : '<row r="1"><c r="A1" t="inlineStr"><is><t>'.$text.'</t></is></c></row>';
            $entries['xl/worksheets/sheet'.$n.'.xml'] = '<?xml version="1.0"?><worksheet xmlns="'.$main.'"><sheetData>'.$cells.'</sheetData></worksheet>';
        }

        $entries['xl/workbook.xml'] = '<?xml version="1.0"?><workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets>'.$workbook.'</sheets></workbook>';
        $entries['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels.'</Relationships>';

        return Fixtures::zip($entries, 'xlsx');
    }

    public function test_sample_rows_are_limited_to_scan_rows(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = ['v'.$i, 'w'.$i];
        }

        $sheet = FileInspector::inspect(self::csv($rows), scanRows: 5)->sheets[0];

        $this->assertCount(5, $sheet->sampleRows);
        $this->assertSame(['v1', 'w1'], $sheet->sampleRows[0]);
    }

    public function test_column_count_ignores_trailing_blank_cells(): void
    {
        $sheet = self::firstSheet([['a', 'b', '', ' '], ['c', 'd', 'e', '']]);

        $this->assertSame(3, $sheet->columnCount);
    }

    public function test_inspect_reads_at_most_scan_rows(): void
    {
        $lines = [];
        for ($i = 0; $i < 300_000; $i++) {
            $lines[] = "{$i},name{$i},x";
        }
        $path = Fixtures::file(implode("\n", $lines)."\n", 'csv');
        unset($lines);

        $start = microtime(true);
        $sheet = FileInspector::inspect($path)->sheets[0];

        $this->assertLessThan(0.5, microtime(true) - $start);
        $this->assertCount(20, $sheet->sampleRows);
    }

    public function test_count_cells_stops_early(): void
    {
        $rows = [];
        for ($i = 0; $i < 1000; $i++) {
            $rows[] = array_fill(0, 10, 'x');
        }

        $count = FileInspector::countCells(self::csv($rows), stopAbove: 500);

        $this->assertTrue($count->exceeded);
        $this->assertLessThanOrEqual(51, $count->rows);
        $this->assertGreaterThan(500, $count->cells);
    }

    public function test_count_cells_counts_non_empty_only_and_trailing_blanks_trimmed(): void
    {
        $count = FileInspector::countCells(self::csv([
            ['a', '', 'c', '', ''],
            [],
            ['d', 'e', '', '', ''],
        ]), stopAbove: 100);

        $this->assertFalse($count->exceeded);
        $this->assertSame(3, $count->rows);
        $this->assertSame(3, $count->columns);
        $this->assertSame(4, $count->cells);
    }

    public function test_count_cells_selects_sheet_by_name(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = Spreadsheet::write($path);
        $writer->sheet('A')->addRow([1]);
        $writer->sheet('B')->addRow([1, 2, 3]);
        $writer->close();

        $this->assertSame(3, FileInspector::countCells($path, 10, 'B')->cells);
        $this->assertSame(1, FileInspector::countCells($path, 10)->cells);
    }
}
