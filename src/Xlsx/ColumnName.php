<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

use InvalidArgumentException;

/**
 * Converts between A1-style column letters and zero-based column indexes.
 */
final class ColumnName
{
    /** Index of column XFD, the last column of an OOXML worksheet. */
    public const MAX_INDEX = 16383;

    /**
     * Zero-based column index of a cell reference or bare column name: 'A1' is 0, 'AA7' is 26.
     *
     * @throws InvalidArgumentException when the reference has no column letters, anything but
     *                                  digits after them, or a column past XFD
     */
    public static function toIndex(string $reference): int
    {
        $length = strlen($reference);
        $index = 0;
        $position = 0;

        // At most three letters (XFD); a longer run is rejected below by the range check.
        while ($position < $length && $position < 4) {
            $byte = ord($reference[$position]) | 0x20; // ASCII lowercase

            if ($byte < 97 || $byte > 122) {
                break;
            }

            $index = $index * 26 + $byte - 96;
            $position++;
        }

        if ($position === 0 || $index - 1 > self::MAX_INDEX
            || ($position < $length && strspn($reference, '0123456789', $position) !== $length - $position)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a valid cell reference.', $reference));
        }

        return $index - 1;
    }

    /**
     * Column letters of a zero-based index: 0 is 'A', 26 is 'AA'.
     */
    public static function fromIndex(int $index): string
    {
        if ($index < 0 || $index > self::MAX_INDEX) {
            throw new InvalidArgumentException(sprintf('Column index %d is outside 0..%d.', $index, self::MAX_INDEX));
        }

        $name = '';

        for ($index++; $index > 0; $index = intdiv($index - 1, 26)) {
            $name = chr(65 + ($index - 1) % 26).$name;
        }

        return $name;
    }
}
