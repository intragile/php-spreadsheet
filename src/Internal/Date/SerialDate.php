<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Date;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Converts between spreadsheet date serials and date-times.
 *
 * A serial counts days since the workbook epoch, its fraction the time of day. Results are
 * UTC-naive wall-clock values rounded to whole seconds. The 1900 system reproduces Excel's
 * fictitious 1900-02-29 (serial 60), which has no real calendar date:
 * serial 60 is reported as 1900-02-28.
 *
 * @internal
 */
final class SerialDate
{
    private const SECONDS_PER_DAY = 86400;

    /** Serial 60 is 1900-02-29, a day that never existed (Lotus 1-2-3 treated 1900 as a leap year). */
    private const PHANTOM_LEAP_DAY = 60;

    /** Day number (since 1970-01-01) of serial 0 in each system; the 1900 system is shifted by one before the phantom day. */
    private const EPOCH_1900_AFTER_PHANTOM = -25569;

    private const EPOCH_1900_BEFORE_PHANTOM = -25568;

    private const EPOCH_1904 = -24107;

    public static function toDateTime(float $serial, bool $date1904 = false): DateTimeImmutable
    {
        if (! is_finite($serial)) {
            throw new InvalidArgumentException('A date serial must be a finite number.');
        }

        $seconds = (int) round($serial * self::SECONDS_PER_DAY);
        $days = intdiv($seconds, self::SECONDS_PER_DAY);
        $time = $seconds - $days * self::SECONDS_PER_DAY;

        if ($time < 0) {
            $days--;
            $time += self::SECONDS_PER_DAY;
        }

        if ($date1904) {
            $epoch = self::EPOCH_1904;
        } else {
            $epoch = $days < self::PHANTOM_LEAP_DAY ? self::EPOCH_1900_BEFORE_PHANTOM : self::EPOCH_1900_AFTER_PHANTOM;
        }

        return (new DateTimeImmutable('@0'))
            ->setTimezone(new DateTimeZone('UTC'))
            ->setTimestamp(($epoch + $days) * self::SECONDS_PER_DAY + $time);
    }

    /**
     * Uses the wall-clock fields of $d, ignoring its time zone.
     */
    public static function fromDateTime(DateTimeInterface $d, bool $date1904 = false): float
    {
        $wallClock = new DateTimeImmutable($d->format('Y-m-d H:i:s'), new DateTimeZone('UTC'));
        $timestamp = $wallClock->getTimestamp();
        $unixDays = intdiv($timestamp, self::SECONDS_PER_DAY);
        $time = $timestamp - $unixDays * self::SECONDS_PER_DAY;

        if ($time < 0) {
            $unixDays--;
            $time += self::SECONDS_PER_DAY;
        }

        if ($date1904) {
            $days = $unixDays - self::EPOCH_1904;
        } else {
            $days = $unixDays - self::EPOCH_1900_AFTER_PHANTOM;

            // Dates before 1900-03-01 sit one serial lower, as the phantom day is not in the calendar.
            if ($days <= self::PHANTOM_LEAP_DAY) {
                $days--;
            }
        }

        return $days + $time / self::SECONDS_PER_DAY;
    }
}
