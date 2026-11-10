<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal\Date;

use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Internal\Date\IsoDate;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class IsoDateTest extends TestCase
{
    public function test_parses_a_date_time(): void
    {
        $this->assertSame('2025-01-31 13:45:00', IsoDate::parse('2025-01-31T13:45:00')->format('Y-m-d H:i:s'));
    }

    public function test_a_trailing_newline_is_rejected(): void
    {
        $this->expectException(CorruptFile::class);

        IsoDate::parse("2025-01-31\n");
    }
}
