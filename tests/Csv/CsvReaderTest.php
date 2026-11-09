<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Csv;

use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Csv\CsvReader;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;

final class CsvReaderTest extends TestCase
{
    public function test_hungarian_excel_export(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/hu-excel-1250.csv'), new ReadOptions);
        $rows = iterator_to_array($reader->rows());

        $this->assertSame(['Név', 'Összeg', 'Város'], $rows[1]);
        $this->assertSame(['Árvíztűrő tükörfúrógép', '1 234,56', 'Győr'], $rows[2]);
        $this->assertSame(['Őrmester Ügyfél', '99,5', 'Szőlős'], $rows[3]);
    }

    public function test_utf8_bom_is_removed(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions))->rows());

        $this->assertSame('id', $rows[1][0]);
        $this->assertSame(['2', 'Bob'], $rows[3]);
    }

    public function test_utf16le_is_decoded(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::committed('csv/utf16le-tab.txt'), new ReadOptions))->rows());

        $this->assertSame(['id', 'name'], $rows[1]);
        $this->assertSame(['1', 'Árpád'], $rows[2]);
    }

    public function test_quoted_newline_stays_in_cell(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::committed('csv/quoted-multiline.csv'), new ReadOptions))->rows());

        $this->assertSame(['1', "line one\nline two"], $rows[2]);
        $this->assertSame([1, 2, 3], array_keys($rows));
    }

    public function test_manual_override_wins(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/hu-excel-1250.csv'), new ReadOptions(csvDelimiter: ','));
        $rows = iterator_to_array($reader->rows());

        $this->assertCount(1, $rows[1]);
        $this->assertSame('Név;Összeg;Város', $rows[1][0]);
    }

    public function test_encoding_override_wins(): void
    {
        $path = Fixtures::file(mb_convert_encoding("a;b\nő;1\n", 'ISO-8859-2', 'UTF-8'), 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions(csvEncoding: 'ISO-8859-2')))->rows());

        $this->assertSame(['ő', '1'], $rows[2]);
    }

    public function test_iso_8859_2_is_reachable_through_the_override(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::file("a;b\n\xF5;1\n", 'csv'), new ReadOptions(csvEncoding: 'ISO-8859-2')))->rows());

        $this->assertSame(['ő', '1'], $rows[2]);
    }

    public function test_concurrent_generators_are_independent_and_close_releases_all(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);
        $first = $reader->rows();
        $second = $reader->rows();

        $this->assertSame(['id', 'name'], $first->current());
        $this->assertSame(['id', 'name'], $second->current());
        $first->next();
        $this->assertSame(['1', 'Alice'], $first->current());
        $this->assertSame(['id', 'name'], $second->current());

        $reader->close();
    }

    public function test_empty_lines_skipped_and_row_numbers_preserved(): void
    {
        $path = Fixtures::file("a,b\n1,2\n\n3,4\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rows());

        $this->assertSame([1, 2, 4], array_keys($rows));
    }

    public function test_rows_of_only_empty_cells_are_skipped(): void
    {
        $path = Fixtures::file("a,b\n,\n1,2\n", 'csv');

        $this->assertSame([1, 3], array_keys(iterator_to_array((new CsvReader($path, new ReadOptions))->rows())));
    }

    public function test_values_are_strings(): void
    {
        $path = Fixtures::file("id,v\n007,1e5\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rows());

        $this->assertSame(['007', '1e5'], $rows[2]);
    }

    public function test_rows_with_header_dedupes(): void
    {
        $path = Fixtures::file("a, a ,\n1,2,3\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rowsWithHeader());

        $this->assertSame([2], array_keys($rows));
        $this->assertSame(['a', 'a_2', 'column_3'], array_keys($rows[2]));
        $this->assertSame(['a' => '1', 'a_2' => '2', 'column_3' => '3'], $rows[2]);
    }

    public function test_rows_with_header_pads_short_rows_and_names_extra_cells(): void
    {
        $path = Fixtures::file("a,b\n1\n1,2,3\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rowsWithHeader());

        $this->assertSame(['a' => '1', 'b' => null], $rows[2]);
        $this->assertSame(['a' => '1', 'b' => '2', 'column_3' => '3'], $rows[3]);
    }

    public function test_rows_with_header_on_a_later_header_row(): void
    {
        $path = Fixtures::file("title\nx,y\n1,2\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rowsWithHeader(0, 2));

        $this->assertSame([3 => ['x' => '1', 'y' => '2']], $rows);
    }

    public function test_sheets(): void
    {
        $sheets = (new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions))->sheets();

        $this->assertCount(1, $sheets);
        $this->assertSame('Sheet1', $sheets[0]->name);
        $this->assertSame(0, $sheets[0]->index);
    }

    public function test_sheet_selector_accepts_zero_and_sheet1_only(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);

        $this->assertNotEmpty(iterator_to_array($reader->rows('Sheet1')));

        $this->expectException(InvalidArgumentException::class);
        $reader->rows(1);
    }

    public function test_unknown_sheet_name_is_rejected_for_header_rows_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions))->rowsWithHeader('Other');
    }

    public function test_missing_file(): void
    {
        $this->expectException(SpreadsheetException::class);
        new CsvReader('/nonexistent/file.csv', new ReadOptions);
    }

    public function test_implements_reader_and_close_is_idempotent(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);

        $this->assertInstanceOf(Reader::class, $reader);
        $reader->close();
        $reader->close();
        $this->assertNotEmpty(iterator_to_array($reader->rows()));
    }

    public function test_memory_flat(): void
    {
        $small = $this->writeRows(30_000);
        $large = $this->writeRows(300_000);

        $smallGrowth = self::peakGrowth(fn () => $this->consume($small));
        $largeGrowth = self::peakGrowth(fn () => $this->consume($large));

        $this->assertLessThan(4 * 1024 * 1024, $largeGrowth - $smallGrowth);
    }

    private function consume(string $path): void
    {
        $count = 0;

        foreach ((new CsvReader($path, new ReadOptions))->rows() as $row) {
            $count += count($row);
        }

        $this->assertGreaterThan(0, $count);
    }

    private function writeRows(int $count): string
    {
        $path = Fixtures::path('csv');
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);

        for ($i = 1; $i <= $count; $i++) {
            fwrite($handle, "{$i};Árvíztűrő tükörfúrógép {$i};\"quoted; value\";".($i % 97).",5\n");
        }

        fclose($handle);

        return $path;
    }
}
