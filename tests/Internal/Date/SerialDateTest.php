<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal\Date;

use DateTimeImmutable;
use DateTimeZone;
use IntrAgile\Spreadsheet\Internal\Date\SerialDate;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class SerialDateTest extends TestCase
{
    public function test_serial_roundtrip(): void
    {
        $date = SerialDate::toDateTime(45658.5);

        $this->assertSame('2025-01-01 12:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame(45658.5, SerialDate::fromDateTime($date));
    }

    public function test_1904_system(): void
    {
        $this->assertSame('1904-01-01', SerialDate::toDateTime(0, true)->format('Y-m-d'));
        $this->assertSame(1.0, SerialDate::fromDateTime(new DateTimeImmutable('1904-01-02'), true));
    }

    public function test_serial_60_is_1900_02_28_or_29_per_excel(): void
    {
        // Serial 60 is Excel's fictitious 1900-02-29; it is reported as the last real day before it.
        $this->assertSame('1900-02-28 00:00:00', SerialDate::toDateTime(60)->format('Y-m-d H:i:s'));
        $this->assertSame('1900-02-28 12:00:00', SerialDate::toDateTime(60.5)->format('Y-m-d H:i:s'));
        $this->assertSame('1900-03-01', SerialDate::toDateTime(61)->format('Y-m-d'));
        $this->assertSame('1900-02-28', SerialDate::toDateTime(59)->format('Y-m-d'));
        $this->assertSame('1900-01-01', SerialDate::toDateTime(1)->format('Y-m-d'));
        $this->assertSame(59.0, SerialDate::fromDateTime(new DateTimeImmutable('1900-02-28')));
        $this->assertSame(61.0, SerialDate::fromDateTime(new DateTimeImmutable('1900-03-01')));
        $this->assertSame(1.0, SerialDate::fromDateTime(new DateTimeImmutable('1900-01-01')));
    }

    public function test_rounds_to_whole_seconds(): void
    {
        $this->assertSame('2025-01-02 00:00:00', SerialDate::toDateTime(45658.999999999)->format('Y-m-d H:i:s'));
    }

    public function test_result_is_utc_and_wall_clock_is_preserved_from_other_zones(): void
    {
        $this->assertSame('UTC', SerialDate::toDateTime(45658)->getTimezone()->getName());

        $local = new DateTimeImmutable('2025-01-01 12:00:00', new DateTimeZone('Europe/Budapest'));
        $this->assertSame(45658.5, SerialDate::fromDateTime($local));
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function outOfRange(): iterable
    {
        yield 'negative' => [-0.5];
        yield 'past 9999-12-31' => [2958466.0];
        yield 'huge' => [1e15];
        yield 'infinite' => [INF];
        yield 'not a number' => [NAN];
    }

    #[DataProvider('outOfRange')]
    public function test_serials_outside_the_calendar_are_rejected_without_warnings(float $serial): void
    {
        self::withoutPhpErrors(function () use ($serial): void {
            $this->expectException(InvalidArgumentException::class);

            SerialDate::toDateTime($serial);
        });
    }

    public function test_calendar_bounds(): void
    {
        $this->assertSame('9999-12-31 23:59:59', SerialDate::toDateTime(2958465.99999)->format('Y-m-d H:i:s'));
        $this->assertSame('1899-12-31', SerialDate::toDateTime(0)->format('Y-m-d'));
    }
}
