<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal;

use IntrAgile\Spreadsheet\Internal\Xml\XmlEscaper;
use InvalidArgumentException;

/**
 * Sheet-name rules shared by every writer: the strictest common denominator, so a workbook that
 * is valid in one format is valid in all of them.
 *
 * @internal
 */
final class SheetName
{
    public const MAX_LENGTH = 31;

    /**
     * @param  array<string, true>  $taken  lowercased names already used in the workbook
     *
     * @throws InvalidArgumentException naming the offending sheet
     */
    public static function validate(string $name, array $taken): void
    {
        $reason = match (true) {
            $name === '' => 'it is empty',
            ! mb_check_encoding($name, 'UTF-8') => 'it is not valid UTF-8',
            mb_strlen($name, 'UTF-8') > self::MAX_LENGTH => 'it is longer than '.self::MAX_LENGTH.' characters',
            strpbrk($name, '[]:*?/\\') !== false => 'it contains one of [ ] : * ? / \\',
            preg_match('/[\x00-\x1F]/', $name) === 1 => 'it contains a control character',
            ! XmlEscaper::isLegal($name) => 'it contains a character the workbook cannot hold in XML (such as U+FFFE or U+FFFF)',
            $name[0] === "'" || $name[-1] === "'" => 'it starts or ends with an apostrophe',
            isset($taken[mb_strtolower($name, 'UTF-8')]) => 'a sheet with this name already exists (names ignore case)',
            default => null,
        };

        if ($reason !== null) {
            throw new InvalidArgumentException(sprintf('Invalid sheet name "%s": %s.', $name, $reason));
        }
    }
}
