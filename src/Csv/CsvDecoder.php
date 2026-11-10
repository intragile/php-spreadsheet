<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use InvalidArgumentException;

/**
 * Lossless conversion of CSV input to UTF-8, as a whole string or as a stream filter.
 *
 * Single-byte code pages are translated through a 256-entry table built once per encoding; a byte
 * the code page leaves undefined becomes U+FFFD. UTF-16 and UTF-32 are decoded with mbstring, an
 * incomplete code unit or surrogate pair at the end of a chunk is carried over to the next one,
 * and invalid sequences become U+FFFD. No conversion ever raises a PHP warning or drops data.
 *
 * @internal
 */
final class CsvDecoder
{
    public const FILTER = 'intragile.spreadsheet.csv-decode';

    private const REPLACEMENT = "\u{FFFD}";

    private const SINGLE_BYTE = '/^(?:(?:windows|cp|ibm)-?(?:125[0-8]|437|737|775|85[0-8]|86[0-9]|874)|iso-?8859-?\d{1,2}|latin-?\d{1,2}|koi8-?[ru]|mac(?:roman|intosh)?|(?:us-?)?ascii)$/i';

    /** @var array<string, array<string, string>> normalized encoding => byte => UTF-8 */
    private static array $tables = [];

    /**
     * @return array{table: array<string, string>|null, encoding: string|null, unit: int}
     *
     * @throws InvalidArgumentException when the encoding is not supported
     */
    public static function plan(string $encoding): array
    {
        $upper = strtoupper(trim($encoding));
        $canonical = self::canonical($encoding);

        $unit = match ($canonical) {
            'UTF-16', 'UTF-16BE', 'UTF-16LE' => 2,
            'UTF-32', 'UTF-32BE', 'UTF-32LE' => 4,
            default => 0,
        };

        if ($unit > 0) {
            // Without a byte-order suffix and without a mark (the reader resolves a mark before
            // this point) the unit order is big-endian, as RFC 2781 prescribes.
            $name = str_ends_with($canonical, 'LE') || str_ends_with($canonical, 'BE') ? $canonical : $canonical.'BE';

            return ['table' => null, 'encoding' => $name, 'unit' => $unit];
        }

        if (preg_match(self::SINGLE_BYTE, $upper) !== 1) {
            throw self::unsupported($encoding);
        }

        return ['table' => self::$tables[$upper] ??= self::buildTable($encoding), 'encoding' => null, 'unit' => 1];
    }

    /**
     * Upper-case encoding name with the UCS-2 and UCS-4 aliases mapped to UTF-16 and UTF-32.
     */
    public static function canonical(string $encoding): string
    {
        return str_replace(['UCS-2', 'UCS-4'], ['UTF-16', 'UTF-32'], strtoupper(trim($encoding)));
    }

    /**
     * Decodes a complete string; a trailing incomplete code unit becomes U+FFFD.
     *
     * @throws InvalidArgumentException when the encoding is not supported
     */
    public static function decode(string $bytes, string $encoding): string
    {
        if (strcasecmp($encoding, 'UTF-8') === 0) {
            return $bytes;
        }

        $plan = self::plan($encoding);

        return $plan['table'] !== null ? strtr($bytes, $plan['table']) : self::decodeUnits($bytes, (string) $plan['encoding']);
    }

    /**
     * Decodes whole UTF-16 or UTF-32 code units.
     */
    public static function decodeUnits(string $bytes, string $encoding): string
    {
        if ($bytes === '') {
            return '';
        }

        $previous = mb_substitute_character();
        mb_substitute_character(0xFFFD);

        try {
            $decoded = mb_convert_encoding($bytes, 'UTF-8', $encoding);
        } finally {
            mb_substitute_character($previous);
        }

        return is_string($decoded) ? $decoded : self::REPLACEMENT;
    }

    /**
     * Bytes at the end of $bytes that cannot be decoded yet: an incomplete code unit, or a lone
     * high surrogate whose partner may arrive with the next chunk.
     */
    public static function incompleteTail(string $bytes, string $encoding, int $unit): int
    {
        $length = strlen($bytes);
        $tail = $length % $unit;

        if ($unit === 2 && $length - $tail >= 2) {
            $high = ord($bytes[$length - $tail - (str_ends_with($encoding, 'LE') ? 1 : 2)]);

            if ($high >= 0xD8 && $high <= 0xDB) {
                $tail += 2;
            }
        }

        return $tail;
    }

    public static function register(): void
    {
        if (! in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, CsvDecodeFilter::class);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function buildTable(string $encoding): array
    {
        $table = [];
        set_error_handler(static fn (): bool => true);

        try {
            if (iconv($encoding, 'UTF-8', 'A') !== 'A') {
                throw self::unsupported($encoding);
            }

            for ($byte = 0; $byte < 256; $byte++) {
                $char = chr($byte);
                $decoded = iconv($encoding, 'UTF-8', $char);

                if ($decoded === false || $decoded === '') {
                    $table[$char] = self::REPLACEMENT;
                } elseif ($decoded !== $char) {
                    $table[$char] = $decoded;
                }
            }
        } finally {
            restore_error_handler();
        }

        return $table;
    }

    private static function unsupported(string $encoding): InvalidArgumentException
    {
        return new InvalidArgumentException(sprintf(
            'Unsupported CSV encoding "%s"; use UTF-8, UTF-16, UTF-32 or a single-byte code page such as Windows-1250 or ISO-8859-2.',
            $encoding,
        ));
    }
}
