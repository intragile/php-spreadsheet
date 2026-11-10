<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Xls;

use DateTimeImmutable;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Exception\UnsupportedFormat;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use IntrAgile\Spreadsheet\Xls\XlsReader;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Reader\Xls;

final class XlsReaderTest extends TestCase
{
    protected function tearDown(): void
    {
        XlsReader::$adapterClass = Xls::class;

        parent::tearDown();
    }

    public function test_reads_types(): void
    {
        $rows = $this->read('mixed.xls');

        $this->assertSame(['id', 'ratio', 'name', 'flag', 'day', 'note', 'code'], $rows[1]);
        $this->assertSame([1, 1.5, 'text', true, null, null, '00123'], array_replace($rows[2], [4 => null]));
        $this->assertEquals(new DateTimeImmutable('2026-09-14'), $rows[2][4]);
        $this->assertSame(1, $rows[2][0]);
    }

    public function test_formula_returns_cached_value(): void
    {
        $rows = $this->read('mixed.xls');

        $this->assertSame([2, 4, 'tail'], $rows[4]);
    }

    public function test_date_1904_system(): void
    {
        $rows = $this->read('date1904.xls');

        $this->assertEquals(new DateTimeImmutable('2026-09-14'), $rows[1][0]);
    }

    public function test_sheets_and_selection_by_name_and_index(): void
    {
        $reader = new XlsReader(Fixtures::committed('xls/multi-sheet.xls'), new ReadOptions);

        $this->assertEquals([
            new SheetInfo('First', 0, true),
            new SheetInfo('Second', 1, true),
            new SheetInfo('Hidden', 2, false),
        ], $reader->sheets());
        $this->assertSame([1 => ['x'], 2 => ['y']], iterator_to_array($reader->rows('Second')));
        $this->assertSame([1 => ['a', 'b'], 2 => [1, 2]], iterator_to_array($reader->rows(0)));
        $this->assertSame([1 => ['secret']], iterator_to_array($reader->rows(2)));

        try {
            iterator_to_array($reader->rows('Nope'));
            $this->fail('An unknown sheet must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('"Nope" does not exist', $exception->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);

        $reader->rows(9);
    }

    public function test_rows_with_header(): void
    {
        $reader = new XlsReader(Fixtures::committed('xls/mixed.xls'), new ReadOptions);
        $rows = iterator_to_array($reader->rowsWithHeader());

        $this->assertSame([2, 4], array_keys($rows));
        $this->assertSame('text', $rows[2]['name']);
        $this->assertSame('00123', $rows[2]['code']);
        $this->assertSame('tail', $rows[4]['name']);
        $this->assertSame(4, $rows[4]['ratio']);
    }

    public function test_empty_rows_skipped_and_keys_are_real_row_numbers(): void
    {
        $this->assertSame([1, 2, 4], array_keys($this->read('mixed.xls')));
    }

    public function test_cell_limit_checked_before_load(): void
    {
        try {
            new XlsReader(Fixtures::committed('xls/grid-5x5.xls'), new ReadOptions(maxCells: 10));
            $this->fail('The cell limit must be enforced.');
        } catch (LimitExceeded $exception) {
            $this->assertStringContainsString('exceeds maxCells', $exception->getMessage());
        }

        $reader = new XlsReader(Fixtures::committed('xls/grid-5x5.xls'), new ReadOptions(maxCells: 25));

        $this->assertCount(5, iterator_to_array($reader->rows()));
    }

    public function test_missing_phpspreadsheet_throws_unsupported(): void
    {
        XlsReader::$adapterClass = 'Nonexistent\\PhpSpreadsheet\\Reader\\Xls';

        $this->expectException(UnsupportedFormat::class);
        $this->expectExceptionMessage('Reading XLS files requires phpoffice/phpspreadsheet: composer require phpoffice/phpspreadsheet');

        new XlsReader(Fixtures::committed('xls/mixed.xls'), new ReadOptions);
    }

    public function test_truncated_file_is_corrupt(): void
    {
        $bytes = (string) file_get_contents(Fixtures::committed('xls/mixed.xls'));
        $path = Fixtures::file(substr($bytes, 0, 700), 'xls');

        $this->expectException(CorruptFile::class);

        new XlsReader($path, new ReadOptions);
    }

    /**
     * @return array<int, list<mixed>>
     */
    private function read(string $fixture): array
    {
        $reader = new XlsReader(Fixtures::committed('xls/'.$fixture), new ReadOptions);

        try {
            return iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }
}
