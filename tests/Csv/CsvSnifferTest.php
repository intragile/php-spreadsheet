<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Csv;

use IntrAgile\Spreadsheet\Csv\CsvSniffer;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class CsvSnifferTest extends TestCase
{
    public function test_hungarian_excel_export(): void
    {
        $dialect = (new CsvSniffer)->sniff(Fixtures::committed('csv/hu-excel-1250.csv'));

        $this->assertSame(';', $dialect->delimiter);
        $this->assertSame('Windows-1250', $dialect->encoding);
        $this->assertSame(0, $dialect->bomLength);
    }

    public function test_utf8_bom(): void
    {
        $dialect = (new CsvSniffer)->sniff(Fixtures::committed('csv/utf8-bom-comma.csv'));

        $this->assertSame(',', $dialect->delimiter);
        $this->assertSame('UTF-8', $dialect->encoding);
        $this->assertSame(3, $dialect->bomLength);
    }

    public function test_utf16le_tab(): void
    {
        $dialect = (new CsvSniffer)->sniff(Fixtures::committed('csv/utf16le-tab.txt'));

        $this->assertSame("\t", $dialect->delimiter);
        $this->assertSame('UTF-16LE', $dialect->encoding);
        $this->assertSame(2, $dialect->bomLength);
    }

    public function test_utf16be_bom(): void
    {
        $path = Fixtures::file("\xFE\xFF".mb_convert_encoding("a|b\n1|2\n", 'UTF-16BE', 'UTF-8'), 'txt');
        $dialect = (new CsvSniffer)->sniff($path);

        $this->assertSame('UTF-16BE', $dialect->encoding);
        $this->assertSame('|', $dialect->delimiter);
    }

    public function test_plain_utf8_without_bom(): void
    {
        $dialect = (new CsvSniffer)->sniff(Fixtures::file("név,érték\nx,1\n", 'csv'));

        $this->assertSame('UTF-8', $dialect->encoding);
        $this->assertSame(0, $dialect->bomLength);
    }

    public function test_multibyte_character_cut_by_the_sample_limit_is_still_utf8(): void
    {
        $path = Fixtures::file("a,b\n".str_repeat('é', 100)."\n", 'csv');

        $this->assertSame('UTF-8', (new CsvSniffer)->sniff($path, 11)->encoding);
    }

    public function test_windows_1250_is_chosen_when_cp1250_only_letters_are_present(): void
    {
        // 0x8A is a letter in Windows-1250 and a control code in ISO-8859-2.
        $this->assertSame('Windows-1250', (new CsvSniffer)->sniff(Fixtures::file("a;b\n\x8A;1\n", 'csv'))->encoding);
    }

    public function test_delimiter_with_decimal_commas_and_quoted_separators(): void
    {
        $path = Fixtures::file("a;b;c\n\"x,y,z\";1,5;2,5\n\"p,q\";3,5;4,5\n", 'csv');

        $this->assertSame(';', (new CsvSniffer)->sniff($path)->delimiter);
    }

    public function test_pipe_delimiter(): void
    {
        $this->assertSame('|', (new CsvSniffer)->sniff(Fixtures::file("a|b|c\n1|2|3\n", 'csv'))->delimiter);
    }

    public function test_single_column_defaults_to_comma(): void
    {
        $this->assertSame(',', (new CsvSniffer)->sniff(Fixtures::file("a\nb\n", 'csv'))->delimiter);
    }

    public function test_enclosure_is_a_double_quote(): void
    {
        $this->assertSame('"', (new CsvSniffer)->sniff(Fixtures::file("a,b\n", 'csv'))->enclosure);
    }
}
