<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Date;

use DateTimeImmutable;
use DateTimeZone;
use IntrAgile\Spreadsheet\Exception\CorruptFile;

/**
 * Strict ISO 8601 parsing for the date values stored by spreadsheet formats.
 *
 * @internal
 */
final class IsoDate
{
    /**
     * Parses a date, or a date-time with optional seconds, fraction and zone. The wall-clock time
     * is kept, in UTC like dates converted from serials.
     *
     * @throws CorruptFile when the value is not such a date
     */
    public static function parse(string $raw): DateTimeImmutable
    {
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?:T(\d{2}:\d{2}(?::\d{2}(?:\.\d{1,9})?)?)(?:Z|[+-]\d{2}:\d{2})?)?$/', $raw, $match) !== 1) {
            throw new CorruptFile(sprintf('The date value "%s" is not an ISO 8601 date.', $raw));
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $match[1], new DateTimeZone('UTC'));

        // createFromFormat() rolls 2025-02-30 over to March; a round trip catches it.
        if ($date === false || $date->format('Y-m-d') !== $match[1]) {
            throw new CorruptFile(sprintf('The date value "%s" is not a valid date.', $raw));
        }

        if (! isset($match[2])) {
            return $date;
        }

        $parts = explode(':', $match[2]);
        [$hour, $minute] = [(int) $parts[0], (int) $parts[1]];
        $seconds = $parts[2] ?? '0';
        $whole = (int) $seconds;
        $micro = str_contains($seconds, '.') ? (int) substr(str_pad(explode('.', $seconds)[1], 6, '0'), 0, 6) : 0;

        if ($hour > 23 || $minute > 59 || $whole > 59) {
            throw new CorruptFile(sprintf('The date value "%s" is not a valid time.', $raw));
        }

        return $date->setTime($hour, $minute, $whole, $micro);
    }
}
