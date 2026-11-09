<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests;

use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Style;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;

final class CellTest extends TestCase
{
    public function test_formula_must_start_with_equals(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Cell::formula('SUM(A1)');
    }

    public function test_kinds_and_style_are_exposed(): void
    {
        $style = Style::make()->bold();

        $this->assertSame(Cell::KIND_TEXT, Cell::text('00123', $style)->kind);
        $this->assertSame(Cell::KIND_FORMULA, Cell::formula('=1+1')->kind);
        $this->assertSame(Cell::KIND_VALUE, Cell::of(5, $style)->kind);
        $this->assertSame($style, Cell::of(5, $style)->style);
        $this->assertSame('00123', Cell::text('00123')->value);
    }
}
