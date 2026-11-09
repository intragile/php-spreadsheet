<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

/**
 * Decides which number formats display a stored number as a date or time of day.
 */
final class NumberFormats
{
    /**
     * Built-in numFmtIds (ECMA-376 Part 1, 18.8.30) that display dates or times. 27–36 and 50–58
     * are East Asian locale formats whose exact pattern depends on the workbook locale.
     */
    private const BUILT_IN_DATES = [
        14 => true, 15 => true, 16 => true, 17 => true, 18 => true, 19 => true, 20 => true, 21 => true, 22 => true,
        27 => true, 28 => true, 29 => true, 30 => true, 31 => true, 32 => true, 33 => true, 34 => true, 35 => true, 36 => true,
        45 => true, 46 => true, 47 => true,
        50 => true, 51 => true, 52 => true, 53 => true, 54 => true, 55 => true, 56 => true, 57 => true, 58 => true,
    ];

    public static function isBuiltInDate(int $numFmtId): bool
    {
        return isset(self::BUILT_IN_DATES[$numFmtId]);
    }

    /**
     * Classifies a format code the way spreadsheet applications do: by the date and time tokens
     * left after removing everything that is displayed literally. Elapsed-time formats such as
     * [h]:mm describe a quantity, not a point in time, and are not dates.
     */
    public static function isDate(string $formatCode): bool
    {
        // Elapsed-time tokens live inside brackets, so they must be found before brackets go.
        if (preg_match('/\[(?:h+|m+|s+)\]/i', $formatCode) === 1) {
            return false;
        }

        // Literal parts are removed in a single left-to-right pass, so that an escaped quote does
        // not open a quoted section: quoted text, a backslash-escaped character, _x and *x padding,
        // and bracketed colours, conditions and locale tags.
        $tokens = preg_replace(['/"[^"]*"?|\\\\.|[_*].|\[[^\]]*\]?/s', '/AM\/PM|A\/P/i'], '', $formatCode) ?? '';
        $tokens = strtolower($tokens);

        // Any remaining day, month, year, hour, minute or second token makes it a date-time ("m"
        // is minutes next to h or s and months otherwise, a date-time either way).
        return strpbrk($tokens, 'dmyhs') !== false;
    }
}
