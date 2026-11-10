<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal;

/**
 * Column width arithmetic shared by the XLSX and ODS writers. Widths are counted in characters
 * of the default font (11 pt Calibri, whose digits are 7 pixels wide at 96 dpi).
 *
 * @internal
 */
final class ColumnWidth
{
    /** Widest column a spreadsheet application accepts, in characters. */
    public const MAX = 255.0;

    /** Characters a long month or weekday name takes ("szeptember", "Donnerstag"). */
    private const LONG_NAME = 10;

    /** Room for wider glyphs such as bold text or a proportional separator. */
    private const MARGIN = 1;

    /**
     * The SpreadsheetML width for a column showing $characters digits: the 5-pixel cell padding is
     * added as ECMA-376 Part 1, 18.3.1.13 prescribes, so Excel's width dialog shows $characters.
     */
    public static function toExcel(float $characters): float
    {
        return min(self::MAX, floor(($characters * 7 + 5) / 7 * 256) / 256);
    }

    /**
     * Characters a date or time formatted with $code can take, plus a margin; month and weekday
     * names count as their longest form.
     */
    public static function forDateFormat(string $code): float
    {
        // Locale, colour and condition sections such as [$-40E] or [Red] are not shown.
        $code = (string) preg_replace('/\[[^\]]*\]/', '', $code);
        $length = 0;

        $tokens = '/"([^"]*)"|\\\\(.)|_.|\*.|(mmmmm|mmmm|mmm|dddd|ddd|yyyy|yy|am\/pm|a\/p|[dmhs]{1,2})|(.)/isu';
        preg_match_all($tokens, $code, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);

        foreach ($matches as $match) {
            if ($match[1] !== null) {
                $length += mb_strlen($match[1], 'UTF-8');
            } elseif ($match[2] !== null) {
                $length++;
            } elseif (isset($match[3])) {
                $length += match (strtolower($match[3])) {
                    'mmmmm', 'a/p' => 1,
                    'mmmm', 'dddd' => self::LONG_NAME,
                    'mmm', 'ddd' => 3,
                    'yyyy' => 4,
                    default => 2,
                };
            } elseif (isset($match[4])) {
                $length++;
            } elseif ($match[0][0] === '_') {
                // "_x" leaves the width of x blank; "*x" repeats x to fill the cell and takes no room.
                $length++;
            }
        }

        return min(self::MAX, (float) ($length + self::MARGIN));
    }
}
