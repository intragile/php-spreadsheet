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

    public function test_utf16_and_utf32_text_without_a_mark_is_csv(): void
    {
        foreach (['UTF-16LE', 'UTF-16BE', 'UTF-32LE', 'UTF-32BE'] as $encoding) {
            $path = Fixtures::file(mb_convert_encoding("a;b\nÁrvíztűrő;2\n", $encoding, 'UTF-8'));

            $this->assertSame(Format::Csv, FormatDetector::detect($path), $encoding);
        }
    }

    public function test_utf32_text_with_a_mark_is_csv(): void
    {
        $this->assertSame(Format::Csv, FormatDetector::detect(Fixtures::file("\x00\x00\xFE\xFF".mb_convert_encoding("a;b\n", 'UTF-32BE', 'UTF-8'))));
        $this->assertSame(Format::Csv, FormatDetector::detect(Fixtures::file("\xFF\xFE\x00\x00".mb_convert_encoding("a;b\n", 'UTF-32LE', 'UTF-8'))));
    }

    public function test_binary_with_nul_bytes_is_not_csv(): void
    {
        $this->expectException(UnsupportedFormat::class);

        FormatDetector::detect(Fixtures::file("\x7FELF\x02\x01\x01\x00".str_repeat("\x00\x01\x02\x03\xFF", 50)));
    }
}
