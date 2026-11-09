<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Xlsx;

use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use IntrAgile\Spreadsheet\Xlsx\NumberFormats;
use PHPUnit\Framework\Attributes\DataProvider;

final class NumberFormatsTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function formatCodes(): iterable
    {
        yield 'iso date' => ['yyyy-mm-dd', true];
        yield 'hungarian dotted date' => ['yyyy.mm.dd', true];
        yield 'hungarian long date with locale tag' => ['[$-40E]yyyy\. mmmm d\.', true];
        yield 'libreoffice uppercase' => ['YYYY\-MM\-DD', true];
        yield 'date and time' => ['yyyy-mm-dd hh:mm:ss', true];
        yield 'time of day' => ['h:mm AM/PM', true];
        yield 'month and year' => ['mmm yy', true];
        yield 'general' => ['General', false];
        yield 'percent' => ['0.00%', false];
        yield 'forint' => ['#,##0 "Ft"', false];
        yield 'quoted date letters' => ['0 "days"', false];
        yield 'escaped letters' => ['0\d', false];
        yield 'coloured negative' => ['[Red]-#,##0.00', false];
        yield 'scientific' => ['0.00E+00', false];
        yield 'elapsed hours are a duration' => ['[h]:mm:ss', false];
        yield 'text' => ['@', false];
    }

    #[DataProvider('formatCodes')]
    public function test_is_date(string $formatCode, bool $expected): void
    {
        $this->assertSame($expected, NumberFormats::isDate($formatCode));
    }

    public function test_built_in_date_ids(): void
    {
        $dates = [...range(14, 22), ...range(27, 36), ...range(45, 47), ...range(50, 58)];

        for ($id = 0; $id < 200; $id++) {
            $this->assertSame(in_array($id, $dates, true), NumberFormats::isBuiltInDate($id), "numFmtId {$id}");
        }
    }
}
