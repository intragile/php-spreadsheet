<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal;

use InvalidArgumentException;

/**
 * Number text for cell values: the shortest form that parses back to the same double, with "."
 * as the decimal separator and an exponent such as "1.0E+25" for very large or small magnitudes.
 * The output is valid xsd:double, so it suits SpreadsheetML <v>, ODF office:value and CSV alike.
 *
 * @internal
 */
final class NumberText
{
    /**
     * Formats a single value; for many values in a row, use begin(), format() and end().
     *
     * @throws InvalidArgumentException when the value is NaN or infinite
     */
    public static function fromFloat(float $value): string
    {
        $previous = self::begin();

        try {
            return self::format($value);
        } finally {
            self::end($previous);
        }
    }

    /**
     * Switches serialize_precision to -1, the only setting under which var_export() yields the
     * shortest round-trip form, and returns the setting to restore, or null when it already was -1.
     */
    public static function begin(): ?string
    {
        $previous = ini_get('serialize_precision');

        if ($previous === '-1' || $previous === false) {
            return null;
        }

        ini_set('serialize_precision', '-1');

        return $previous;
    }

    public static function end(?string $previous): void
    {
        if ($previous !== null) {
            ini_set('serialize_precision', $previous);
        }
    }

    /**
     * Formats a value between begin() and end().
     *
     * @throws InvalidArgumentException when the value is NaN or infinite
     */
    public static function format(float $value): string
    {
        // Also catches -0.0: spreadsheets have no negative zero, so it is written as plain zero.
        if ($value === 0.0) {
            return '0';
        }

        if (! is_finite($value)) {
            throw new InvalidArgumentException('Spreadsheet numbers must be finite; got '.var_export($value, true).'.');
        }

        $text = var_export($value, true);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
