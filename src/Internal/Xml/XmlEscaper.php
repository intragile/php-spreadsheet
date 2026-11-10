<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Xml;

/**
 * Escapes text for XML 1.0 content and attribute values.
 *
 * @internal
 */
final class XmlEscaper
{
    /** Characters outside the XML 1.0 Char production. */
    private const ILLEGAL = '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u';

    /**
     * Whether the string is valid UTF-8 made only of characters XML 1.0 can represent, so that
     * text() would keep every character.
     */
    public static function isLegal(string $s): bool
    {
        // An invalid UTF-8 subject makes the Unicode-mode match fail, which also means "not legal".
        return preg_match(self::ILLEGAL, $s) === 0;
    }

    /**
     * Escapes & < > " ' and removes characters XML 1.0 cannot represent, including bytes that
     * are not valid UTF-8.
     */
    public static function text(string $s): string
    {
        // Dropping invalid UTF-8 first keeps the Unicode-mode pattern below from failing on it.
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $s);
        $s = $clean === false ? '' : $clean;

        $stripped = preg_replace(self::ILLEGAL, '', $s);

        return htmlspecialchars($stripped ?? '', ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
    }
}
