<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests;

use IntrAgile\Spreadsheet\Style;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;

final class StyleTest extends TestCase
{
    public function test_equal_styles_share_key(): void
    {
        $this->assertSame(
            Style::make()->bold()->fill('FFFF00')->key(),
            Style::make()->fill('ffff00')->bold()->key(),
        );
    }

    public function test_style_is_immutable(): void
    {
        $a = Style::make();
        $a->bold();

        $this->assertNotSame($a->key(), $a->bold()->key());
        $this->assertSame(Style::make()->key(), $a->key());
    }

    public function test_invalid_hex_color_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Style::make()->fill('red');
    }

    public function test_invalid_alignment_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Style::make()->align('justify');
    }

    public function test_distinct_styles_have_distinct_keys(): void
    {
        $this->assertNotSame(
            Style::make()->numberFormat('0%')->key(),
            Style::make()->numberFormat('0.00')->key(),
        );
    }
}
