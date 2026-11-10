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
     * @throws InvalidArgumentException when the value is NaN or infinite
     */
    public static function fromFloat(float $value): string
    {
        // Also catches -0.0: spreadsheets have no negative zero, so it is written as plain zero.
        if ($value === 0.0) {
            return '0';
        }

        if (! is_finite($value)) {
            throw new InvalidArgumentException('Spreadsheet numbers must be finite; got '.var_export($value, true).'.');
        }

        // var_export() follows serialize_precision; only -1 yields the shortest round-trip form.
        if (ini_get('serialize_precision') === '-1') {
            $text = var_export($value, true);
        } else {
            $previous = ini_set('serialize_precision', '-1');

            try {
                $text = var_export($value, true);
            } finally {
                if ($previous !== false) {
                    ini_set('serialize_precision', $previous);
                }
            }
        }

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
