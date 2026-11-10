<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal;

use IntrAgile\Spreadsheet\Internal\ColumnWidth;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColumnWidthTest extends TestCase
{
    public function test_excel_width_adds_the_cell_padding(): void
    {
        // ECMA-376 18.3.1.13: truncate((characters * 7 + 5) / 7 * 256) / 256 for a 7-pixel digit.
        $this->assertSame(30.7109375, ColumnWidth::toExcel(30));
        $this->assertSame(12.7109375, ColumnWidth::toExcel(12));
        $this->assertSame(1.7109375, ColumnWidth::toExcel(1));
        $this->assertSame(255.0, ColumnWidth::toExcel(255));
    }

    /**
     * @return array<string, array{string, float}>
     */
    public static function dateFormats(): array
    {
        return [
            'iso date' => ['yyyy-mm-dd', 11.0],
            'iso date time' => ['yyyy-mm-dd hh:mm:ss', 20.0],
            'hungarian date' => ['yyyy. mm. dd.', 14.0],
            'month name' => ['mmmm d, yyyy', 20.0],
            'short month and weekday' => ['ddd, d mmm', 12.0],
            'quoted text' => ['dd "de" mmmm', 17.0],
            'escaped and locale' => ['[$-40E]yyyy\.mm\.dd', 11.0],
            'am pm' => ['h:mm AM/PM', 9.0],
            'fractional seconds' => ['hh:mm:ss.000', 13.0],
            'single letter month' => ['mmmmm', 2.0],
        ];
    }

    #[DataProvider('dateFormats')]
    public function test_date_format_width_fits_the_longest_display(string $code, float $expected): void
    {
        $this->assertSame($expected, ColumnWidth::forDateFormat($code));
    }

    public function test_date_format_width_is_capped(): void
    {
        $this->assertSame(255.0, ColumnWidth::forDateFormat(str_repeat('yyyy', 100)));
    }
}
