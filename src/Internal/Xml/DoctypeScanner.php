<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Xml;

/**
 * Looks for an internal DTD subset in the raw bytes at the start of an XML document.
 *
 * Older libxml versions serialise a DOCTYPE node together with its internal subset, which lets
 * the reader inspect it; libxml 2.14 and later return nothing for it. The raw prolog is the only
 * source that does not depend on the parser version, so it is scanned directly. Anything that
 * cannot be read with certainty counts as a subset, because refusing a document is safe and
 * accepting a hostile one is not.
 *
 * @internal
 */
final class DoctypeScanner
{
    private const DECLARATION = '<!DOCTYPE';

    public static function mayHaveInternalSubset(string $prolog): bool
    {
        $prolog = self::toUtf8($prolog);
        $start = self::declarationOffset($prolog);

        if ($start === null) {
            return true;
        }

        $length = strlen($prolog);

        for ($i = $start + strlen(self::DECLARATION); $i < $length; $i++) {
            $char = $prolog[$i];

            if ($char === '"' || $char === "'") {
                $close = strpos($prolog, $char, $i + 1);

                if ($close === false) {
                    return true;
                }

                $i = $close;

                continue;
            }

            if ($char === '[') {
                return true;
            }

            if ($char === '>') {
                return false;
            }
        }

        return true;
    }

    /**
     * Offset of the DOCTYPE declaration, skipping comments and processing instructions before it;
     * null when it is not within the given bytes.
     */
    private static function declarationOffset(string $prolog): ?int
    {
        $offset = 0;
        $length = strlen($prolog);

        while ($offset < $length) {
            $next = strpos($prolog, '<', $offset);

            if ($next === false) {
                return null;
            }

            if (substr_compare($prolog, self::DECLARATION, $next, strlen(self::DECLARATION)) === 0) {
                return $next;
            }

            $terminator = match (true) {
                substr_compare($prolog, '<!--', $next, 4) === 0 => '-->',
                substr_compare($prolog, '<?', $next, 2) === 0 => '?>',
                default => null,
            };

            // Any other markup is the root element: a DOCTYPE can no longer follow.
            if ($terminator === null) {
                return null;
            }

            $end = strpos($prolog, $terminator, $next + 2);

            if ($end === false) {
                return null;
            }

            $offset = $end + strlen($terminator);
        }

        return null;
    }

    private static function toUtf8(string $prolog): string
    {
        $encoding = match (true) {
            str_starts_with($prolog, "\xFF\xFE") => 'UTF-16LE',
            str_starts_with($prolog, "\xFE\xFF") => 'UTF-16BE',
            default => null,
        };

        if ($encoding === null) {
            return $prolog;
        }

        $body = substr($prolog, 2);
        // A capture may end in the middle of a code unit.
        $body = substr($body, 0, strlen($body) - strlen($body) % 2);

        return mb_convert_encoding($body, 'UTF-8', $encoding);
    }
}
