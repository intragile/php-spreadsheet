<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Ooxml;

use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use IntrAgile\Spreadsheet\Xlsx\NumberFormats;
use XMLReader;

/**
 * Reads the part of a SpreadsheetML styles part the reader needs: which cell formats display a
 * date or time.
 *
 * @internal
 */
final class StyleSheet
{
    /** Most cell formats (xf) or number formats a styles part may declare; Excel allows about 64,000. */
    private const MAX_STYLE_RECORDS = 65_536;

    /**
     * Builds the xf index => date map from the number formats and cell formats (cellXfs) of the
     * styles part. Conditional formats (dxfs) carry their own numFmt elements, which are ignored.
     *
     * @return array<int, true> cell format (xf) indexes that display a date or time
     *
     * @throws LimitExceeded when the part declares too many number or cell formats
     */
    public static function dateStyles(SafeZip $zip, string $part): array
    {
        $xml = $zip->xmlReader($part, Relationships::MAX_PART_BYTES);
        /** @var array<int, bool> $custom numFmtId => is a date */
        $custom = [];
        /** @var list<int> $cellFormats numFmtId of each xf */
        $cellFormats = [];
        $numFmtsDepth = null;
        $cellXfsDepth = null;

        try {
            while ($xml->read()) {
                $nodeType = $xml->nodeType();

                if ($nodeType === XMLReader::END_ELEMENT) {
                    if ($xml->depth() === $numFmtsDepth) {
                        $numFmtsDepth = null;
                    } elseif ($xml->depth() === $cellXfsDepth) {
                        $cellXfsDepth = null;
                    }

                    continue;
                }

                if ($nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->localName();

                if ($name === 'numFmts') {
                    $numFmtsDepth = $xml->isEmptyElement() ? null : $xml->depth();
                } elseif ($name === 'numFmt' && $numFmtsDepth !== null && $xml->depth() === $numFmtsDepth + 1) {
                    $id = self::unsignedInt($xml->attribute('numFmtId'));

                    if ($id !== null) {
                        if (count($custom) >= self::MAX_STYLE_RECORDS && ! isset($custom[$id])) {
                            throw new LimitExceeded(sprintf('The styles part declares more than %d number formats.', self::MAX_STYLE_RECORDS));
                        }

                        $custom[$id] = NumberFormats::isDate((string) $xml->attribute('formatCode'));
                    }
                } elseif ($name === 'cellXfs') {
                    $cellXfsDepth = $xml->isEmptyElement() ? null : $xml->depth();
                } elseif ($name === 'xf' && $cellXfsDepth !== null && $xml->depth() === $cellXfsDepth + 1) {
                    if (count($cellFormats) >= self::MAX_STYLE_RECORDS) {
                        throw new LimitExceeded(sprintf('The styles part declares more than %d cell formats.', self::MAX_STYLE_RECORDS));
                    }

                    // Every xf keeps its position, as cells refer to it by index; a missing id means General.
                    $cellFormats[] = self::unsignedInt($xml->attribute('numFmtId')) ?? 0;
                }
            }
        } finally {
            $xml->close();
        }

        $dateStyles = [];

        foreach ($cellFormats as $index => $numFmtId) {
            if ($custom[$numFmtId] ?? NumberFormats::isBuiltInDate($numFmtId)) {
                $dateStyles[$index] = true;
            }
        }

        return $dateStyles;
    }

    /**
     * A plain non-negative decimal integer within 32 bits, or null.
     */
    private static function unsignedInt(?string $value): ?int
    {
        $value = trim((string) $value);

        if ($value === '' || strlen($value) > 10 || ! ctype_digit($value)) {
            return null;
        }

        $number = (int) $value;

        return $number <= 0xFFFFFFFF ? $number : null;
    }
}
