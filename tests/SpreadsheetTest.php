<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests;

use DateTimeImmutable;
use IntrAgile\Spreadsheet\Detection\FormatDetector;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Exception\UnsupportedFormat;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class SpreadsheetTest extends TestCase
{
    public function test_read_detects_format_by_content(): void
    {
        // An xlsx file with a misleading extension is still read as xlsx.
        $path = Fixtures::path('csv');
        copy(Fixtures::committed('xlsx/excel-mixed.xlsx'), $path);

        $reader = Spreadsheet::read($path);

        try {
            $rows = iterator_to_array($reader->rows());
            $this->assertSame(['Egész', 'Tört', 'Szöveg', 'Logikai', 'Dátum', 'Üres', 'Utolsó'], $rows[1]);
        } finally {
            $reader->close();
        }
    }

    public function test_read_honours_an_explicit_format(): void
    {
        $path = Fixtures::file("a;b\n1;2\n", 'dat');
        $reader = Spreadsheet::read($path, new ReadOptions(csvDelimiter: ';'), Format::Csv);

        try {
            $this->assertSame([1 => ['a', 'b'], 2 => ['1', '2']], iterator_to_array($reader->rows()));
        } finally {
            $reader->close();
        }
    }

    public function test_read_missing_file_names_the_path(): void
    {
        $path = '/nonexistent/dir/missing.xlsx';

        $this->expectException(SpreadsheetException::class);
        $this->expectExceptionMessage('File not found or not readable: '.$path);

        Spreadsheet::read($path);
    }

    public function test_write_infers_format_from_extension_and_unknown_throws(): void
    {
        $path = Fixtures::path('xlsx');
        $writer = Spreadsheet::write($path);
        $writer->addRow(['a', 1])->close();

        $this->assertSame(Format::Xlsx, FormatDetector::detect($path));

        try {
            Spreadsheet::write(Fixtures::path('xls'));
            $this->fail('Writing xls must throw.');
        } catch (UnsupportedFormat $exception) {
            $this->assertStringContainsString('XLS writing is not supported', $exception->getMessage());
        }

        $this->expectException(UnsupportedFormat::class);

        Spreadsheet::write(Fixtures::path('foo'));
    }

    public function test_cross_format_roundtrip(): void
    {
        $row = [42, 3.14, 'szöveg', true, new DateTimeImmutable('2025-01-31'), null, 'utolsó'];
        $results = [];

        foreach (['xlsx', 'ods', 'csv'] as $extension) {
            $path = Fixtures::path($extension);
            Spreadsheet::write($path)->addRow($row)->close();

            $reader = Spreadsheet::read($path);

            try {
                $results[$extension] = iterator_to_array($reader->rows());
            } finally {
                $reader->close();
            }
        }

        $this->assertEquals([1 => $row], $results['xlsx']);
        $this->assertEquals($results['xlsx'], $results['ods']);
        $this->assertSame(
            [1 => ['42', '3.14', 'szöveg', '1', '2025-01-31', '', 'utolsó']],
            $results['csv'],
        );
    }

    public function test_rows_with_header_xlsx(): void
    {
        $path = Fixtures::path('xlsx');
        Spreadsheet::write($path)->addRow(['name', 'qty'])->addRow(['apple', 3])->addRow(['pear', 5])->close();

        $reader = Spreadsheet::read($path);

        try {
            $this->assertSame(
                [2 => ['name' => 'apple', 'qty' => 3], 3 => ['name' => 'pear', 'qty' => 5]],
                iterator_to_array($reader->rowsWithHeader()),
            );
        } finally {
            $reader->close();
        }
    }
}
