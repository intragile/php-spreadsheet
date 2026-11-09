<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Detection;

use IntrAgile\Spreadsheet\Detection\FormatDetector;
use IntrAgile\Spreadsheet\Exception\UnsupportedFormat;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class FormatDetectorTest extends TestCase
{
    public function test_detects_xlsx_ods_csv_from_fixtures_regardless_of_extension(): void
    {
        $expected = ['minimal.xlsx' => Format::Xlsx, 'minimal.ods' => Format::Ods, 'minimal.csv' => Format::Csv];

        foreach ($expected as $name => $format) {
            $copy = Fixtures::file((string) file_get_contents(Fixtures::committed('detect/'.$name)), 'bin');

            $this->assertSame($format, FormatDetector::detect($copy), $name);
        }
    }

    public function test_xls_and_numbers_throw_unsupported_format(): void
    {
        foreach (['legacy.xls' => 'xls', 'sample.numbers' => 'numbers'] as $name => $kind) {
            try {
                FormatDetector::detect(Fixtures::committed('detect/'.$name));
                $this->fail($name.' should be rejected');
            } catch (UnsupportedFormat $e) {
                $this->assertStringContainsString($kind, $e->getMessage());
            }
        }
    }

    public function test_pdf_and_foreign_zip_are_unsupported(): void
    {
        foreach ([
            ['%PDF-1.7 binary', 'pdf'],
            [(string) file_get_contents(Fixtures::zip(['word/document.xml' => '<x/>'])), 'zip'],
        ] as [$bytes, $kind]) {
            try {
                FormatDetector::detect(Fixtures::file($bytes));
                $this->fail('should be rejected');
            } catch (UnsupportedFormat $e) {
                $this->assertStringContainsString($kind, $e->getMessage());
            }
        }
    }

    public function test_windows_1250_text_is_csv(): void
    {
        $path = Fixtures::file("a;b\n\xC1rv\xEDzt\xFBr\xF5;2\n");

        $this->assertSame(Format::Csv, FormatDetector::detect($path));
    }
}
