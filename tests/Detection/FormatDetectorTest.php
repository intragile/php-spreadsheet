<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Detection;

use IntrAgile\Spreadsheet\Detection\FormatDetector;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
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

    public function test_detects_xls_by_workbook_stream(): void
    {
        $this->assertSame(Format::Xls, FormatDetector::detect(Fixtures::committed('xls/mixed.xls')));
        $this->assertSame(Format::Xls, FormatDetector::detect(Fixtures::committed('xls/date1904.xls')));
    }

    public function test_ole2_without_workbook_stays_unsupported(): void
    {
        $this->expectException(UnsupportedFormat::class);
        $this->expectExceptionMessage('legacy binary Excel (xls), a password-protected Office file, or another OLE2 document');

        FormatDetector::detect(Fixtures::committed('detect/word.doc'));
    }

    public function test_workbook_name_beyond_a_chunk_boundary_is_found(): void
    {
        $name = mb_convert_encoding('Workbook', 'UTF-16LE', 'UTF-8')."\0\0";

        foreach ([65_536 - 9, 65_536 - 1, 65_536 * 2 - 17, 200_000] as $offset) {
            $bytes = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\1", $offset - 8).$name.str_repeat("\1", 100);

            $this->assertSame(Format::Xls, FormatDetector::detect(Fixtures::file($bytes)), (string) $offset);
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

    public function test_an_empty_file_is_unsupported_rather_than_an_empty_csv(): void
    {
        $this->expectException(UnsupportedFormat::class);
        $this->expectExceptionMessage('empty file');

        FormatDetector::detect(Fixtures::file(''));
    }

    public function test_a_short_nul_free_binary_still_reads_as_csv(): void
    {
        // Without NUL bytes or a known signature there is nothing to tell a few bytes of binary from text.
        $this->assertSame(Format::Csv, FormatDetector::detect(Fixtures::file("\x89\xAB\xCD\xEF\x01\x02")));
    }

    public function test_a_truncated_zip_is_corrupt_not_unsupported(): void
    {
        $bytes = (string) file_get_contents(Fixtures::zip(['[Content_Types].xml' => str_repeat('<Types/>', 200), 'xl/workbook.xml' => '<workbook/>']));

        $this->expectException(CorruptFile::class);

        FormatDetector::detect(Fixtures::file(substr($bytes, 0, intdiv(strlen($bytes), 2))));
    }

    public function test_xlsb_is_unsupported_not_xlsx(): void
    {
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="bin" ContentType="application/vnd.ms-excel.sheet.binary.macroEnabled.main"/>'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.bin" ContentType="application/vnd.ms-excel.worksheet"/>'
            .'<Override PartName="/xl/styles.bin" ContentType="application/vnd.ms-excel.styles"/>'
            .'<Override PartName="/xl/sharedStrings.bin" ContentType="application/vnd.ms-excel.sharedStrings"/>'
            .'<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'</Types>';
        $path = Fixtures::zip([
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => '<Relationships/>',
            'xl/workbook.bin' => "\x83\x01\x00",
            'xl/worksheets/sheet1.bin' => "\x81\x01\x00",
        ]);

        $this->expectException(UnsupportedFormat::class);
        $this->expectExceptionMessage('xlsb');

        FormatDetector::detect($path);
    }

    public function test_an_encrypted_ooxml_file_is_reported_as_password_protected(): void
    {
        $directory = mb_convert_encoding("Root Entry\0", 'UTF-16LE', 'UTF-8').str_repeat("\0", 106)
            .mb_convert_encoding("EncryptionInfo\0", 'UTF-16LE', 'UTF-8').str_repeat("\0", 98)
            .mb_convert_encoding("EncryptedPackage\0", 'UTF-16LE', 'UTF-8');
        $bytes = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 20_000).$directory.str_repeat("\0", 512);

        try {
            FormatDetector::detect(Fixtures::file($bytes));
            $this->fail('an encrypted file should be rejected');
        } catch (UnsupportedFormat $e) {
            $this->assertStringContainsString('password-protected', $e->getMessage());
            $this->assertStringNotContainsString('legacy binary Excel', $e->getMessage());
        }
    }
}
