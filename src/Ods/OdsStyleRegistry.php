<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Ods;

use IntrAgile\Spreadsheet\Internal\Xml\XmlEscaper;
use IntrAgile\Spreadsheet\Style;
use IntrAgile\Spreadsheet\Xlsx\NumberFormats;
use WeakMap;

/**
 * Collects the distinct cell styles of a workbook being written and renders them, with the
 * number:* data styles they reference, as the automatic styles of content.xml.
 *
 * Equal styles share one entry however many Style instances describe them, and lookups are cached
 * per Style instance, so a style reused across many rows costs one hash lookup.
 *
 * Number formats use Excel-style codes. Digit codes (0, #,##0.00, 0%, literals such as "Ft") and
 * date or time codes (yyyy-mm-dd hh:mm:ss, AM/PM) are translated; a code outside that subset
 * (sections after the first, scientific, fractions, elapsed time) cannot be, so it falls back to
 * a plain number style, or to the default date format for a date cell, instead of failing.
 *
 * @internal
 */
final class OdsStyleRegistry
{
    /** Format applied to date cells whose style has no date format of its own. */
    public const DATE_FORMAT = 'yyyy-mm-dd';

    /** Format applied to date-time cells whose style has no date format of its own. */
    public const DATE_TIME_FORMAT = 'yyyy-mm-dd hh:mm:ss';

    private const VARIANT_PLAIN = 0;

    private const VARIANT_DATE = 1;

    private const VARIANT_DATE_TIME = 2;

    /** @var array<string, string> data style XML (without name) => name */
    private array $dataStyles = [];

    /** @var array<string, string> cell style XML (without name) => name */
    private array $cellStyles = [];

    /** @var WeakMap<Style, array<int, string>> style => variant => cell style name */
    private WeakMap $cache;

    /** @var array<int, string> variant => name for cells without a style */
    private array $unstyled = [];

    public function __construct()
    {
        $this->cache = new WeakMap;
    }

    /**
     * Name of the cell style for a style, or '' when the default style applies.
     *
     * @param  bool  $isDate  the cell holds a date; without a date format of its own the style gets one
     * @param  bool  $hasTime  the date has a time of day, so the default format includes the time
     */
    public function nameFor(?Style $style, bool $isDate = false, bool $hasTime = false): string
    {
        $variant = $isDate ? ($hasTime ? self::VARIANT_DATE_TIME : self::VARIANT_DATE) : self::VARIANT_PLAIN;

        if ($style === null) {
            return $this->unstyled[$variant] ??= $this->register(null, $variant);
        }

        $names = $this->cache[$style] ?? [];

        if (isset($names[$variant])) {
            return $names[$variant];
        }

        $names[$variant] = $this->register($style, $variant);
        $this->cache[$style] = $names;

        return $names[$variant];
    }

    /**
     * The registered styles as children of office:automatic-styles; data styles come first.
     */
    public function toXml(): string
    {
        $xml = '';

        foreach ($this->dataStyles as $body => $name) {
            $xml .= preg_replace('/^<([^\s>]+)/', '<$1 style:name="'.$name.'"', $body, 1);
        }

        foreach ($this->cellStyles as $body => $name) {
            $xml .= '<style:style style:name="'.$name.'" '.$body;
        }

        return $xml;
    }

    private function register(?Style $style, int $variant): string
    {
        $dataStyle = $this->dataStyleFor($style, $variant);
        $parent = ' style:family="table-cell" style:parent-style-name="Default"'.($dataStyle === '' ? '' : ' style:data-style-name="'.$dataStyle.'"');

        $cell = '';
        $paragraph = '';
        $text = '';

        if ($style !== null) {
            $fill = $style->getFill();
            $border = $style->getBorder();
            $cell .= $fill === null ? '' : ' fo:background-color="#'.$fill.'"';
            $cell .= $border === null ? '' : ' fo:border="0.74pt solid #'.$border.'"';
            $cell .= $style->isWrapped() ? ' fo:wrap-option="wrap"' : '';
            $paragraph .= $style->getAlign() === null ? '' : ' fo:text-align="'.$style->getAlign().'"';
            $text .= $style->isBold() ? ' fo:font-weight="bold" style:font-weight-asian="bold" style:font-weight-complex="bold"' : '';
            $text .= $style->isItalic() ? ' fo:font-style="italic" style:font-style-asian="italic" style:font-style-complex="italic"' : '';
            $text .= $style->getFontColor() === null ? '' : ' fo:color="#'.$style->getFontColor().'"';
        }

        $body = $parent.'>'
            .($cell === '' ? '' : '<style:table-cell-properties'.$cell.'/>')
            .($paragraph === '' ? '' : '<style:paragraph-properties'.$paragraph.'/>')
            .($text === '' ? '' : '<style:text-properties'.$text.'/>')
            .'</style:style>';

        return $this->cellStyles[$body] ??= 'ce'.(count($this->cellStyles) + 1);
    }

    /**
     * Name of the data style that renders the cell's number format, '' for none (General).
     */
    private function dataStyleFor(?Style $style, int $variant): string
    {
        $code = $style?->getNumberFormat();

        if ($variant === self::VARIANT_PLAIN) {
            if ($code === null || strcasecmp(trim($code), 'General') === 0) {
                return '';
            }

            $body = NumberFormats::isDate($code) ? self::dateStyle($code) : self::numberStyle($code);
            $body ??= self::PLAIN_NUMBER_STYLE;
        } else {
            $default = $variant === self::VARIANT_DATE_TIME ? self::DATE_TIME_FORMAT : self::DATE_FORMAT;
            $body = ($code !== null && NumberFormats::isDate($code) ? self::dateStyle($code) : null) ?? self::renderDate(self::parts($default));
        }

        return $this->dataStyles[$body] ??= 'N'.(count($this->dataStyles) + 1);
    }

    private const PLAIN_NUMBER_STYLE = '<number:number-style><number:number number:min-integer-digits="1"/></number:number-style>';

    /**
     * Splits a format code's first section into literal text and format characters. Quoted text,
     * backslash escapes, padding (_x, *x), colours, conditions and currency tags are resolved;
     * later sections are ignored.
     *
     * @return list<array{0: bool, 1: string}> [isLiteral, text] pairs; non-literals are single characters
     */
    private static function parts(string $code): array
    {
        $parts = [];
        $length = strlen($code);

        for ($i = 0; $i < $length; $i++) {
            $c = $code[$i];

            if ($c === ';') {
                break;
            }

            if ($c === '"') {
                $end = strpos($code, '"', $i + 1);
                $end = $end === false ? $length : $end;
                $parts[] = [true, substr($code, $i + 1, $end - $i - 1)];
                $i = $end;
            } elseif ($c === '\\') {
                $parts[] = [true, $code[$i + 1] ?? ''];
                $i++;
            } elseif ($c === '_') {
                $parts[] = [true, ' '];
                $i++;
            } elseif ($c === '*') {
                $i++;
            } elseif ($c === '[') {
                $end = strpos($code, ']', $i);
                $end = $end === false ? $length : $end;
                $tag = substr($code, $i + 1, $end - $i - 1);

                if (str_starts_with($tag, '$')) {
                    $parts[] = [true, explode('-', substr($tag, 1), 2)[0]];
                }

                $i = $end;
            } else {
                $parts[] = [false, $c];
            }
        }

        return $parts;
    }

    /**
     * @return string|null the number:number-style or number:percentage-style, null when the code is outside the supported subset
     */
    private static function numberStyle(string $code): ?string
    {
        $parts = self::parts($code);
        $first = null;
        $last = null;

        foreach ($parts as $index => [$literal, $text]) {
            if (! $literal && strpbrk($text, '0#?') !== false) {
                $first ??= $index;
                $last = $index;
            }
        }

        if ($first === null || $last === null) {
            return null;
        }

        $integer = '';
        $decimals = '';
        $fraction = false;
        $before = '';
        $after = '';
        $percent = false;

        foreach ($parts as $index => [$literal, $text]) {
            if ($index >= $first && $index <= $last) {
                if ($literal || strpbrk($text, '0#?,.') === false) {
                    return null;
                }

                if ($text === '.') {
                    if ($fraction) {
                        return null;
                    }

                    $fraction = true;
                } elseif ($fraction && $text === ',') {
                    return null;
                } elseif ($fraction) {
                    $decimals .= $text;
                } else {
                    $integer .= $text;
                }

                continue;
            }

            if (! $literal && ($text === '/' || $text === ',' || $text === '.' || ctype_alnum($text))) {
                return null;
            }

            $percent = $percent || (! $literal && $text === '%');

            if ($index < $first) {
                $before .= $text;
            } else {
                $after .= $text;
            }
        }

        // A trailing comma scales by a thousand, which the data styles cannot express.
        if (str_ends_with($integer, ',') || str_contains($integer, ',,')) {
            return null;
        }

        $number = '<number:number number:decimal-places="'.strlen($decimals).'" number:min-integer-digits="'.substr_count($integer, '0').'"'
            .(str_contains($integer, ',') ? ' number:grouping="true"' : '').'/>';
        $element = $percent ? 'percentage-style' : 'number-style';

        return '<number:'.$element.'>'
            .($before === '' ? '' : '<number:text>'.XmlEscaper::text($before).'</number:text>')
            .$number
            .($after === '' ? '' : '<number:text>'.XmlEscaper::text($after).'</number:text>')
            .'</number:'.$element.'>';
    }

    /**
     * @return string|null the number:date-style, null when the code has tokens outside the supported subset
     */
    private static function dateStyle(string $code): ?string
    {
        $parts = self::parts($code);
        $count = count($parts);

        for ($i = 0; $i < $count; $i++) {
            [$isLiteral, $text] = $parts[$i];
            $lower = strtolower($text);

            if ($isLiteral || strpbrk($lower, 'ymdhs') !== false || ! ctype_alnum($text) && ! ($text === '.' && ($parts[$i + 1][1] ?? '') === '0')) {
                continue;
            }

            // AM/PM and A/P are the only other letters; fractional seconds (".0") have no equivalent.
            $length = $lower === 'a' ? self::amPmLength($parts, $i, $count) : 0;

            if ($length === 0) {
                return null;
            }

            $i += $length - 1;
        }

        return self::renderDate($parts);
    }

    /**
     * Characters of an AM/PM or A/P marker starting at part $i, 0 when there is none.
     *
     * @param  list<array{0: bool, 1: string}>  $parts
     */
    private static function amPmLength(array $parts, int $i, int $count): int
    {
        $rest = '';

        for ($j = $i; $j < min($count, $i + 5); $j++) {
            $rest .= $parts[$j][0] ? '' : $parts[$j][1];
        }

        return stripos($rest, 'am/pm') === 0 ? 5 : (stripos($rest, 'a/p') === 0 ? 3 : 0);
    }

    /**
     * @param  list<array{0: bool, 1: string}>  $parts
     */
    private static function renderDate(array $parts): string
    {
        $tokens = [];
        $count = count($parts);

        for ($i = 0; $i < $count; $i++) {
            [$isLiteral, $text] = $parts[$i];

            if ($isLiteral) {
                self::addText($tokens, $text);

                continue;
            }

            $lower = strtolower($text);

            if (strpbrk($lower, 'ymdhs') !== false) {
                $run = 1;

                while ($i + 1 < $count && ! $parts[$i + 1][0] && strtolower($parts[$i + 1][1]) === $lower) {
                    $run++;
                    $i++;
                }

                $tokens[] = [$lower, (string) $run];
            } elseif ($lower === 'a') {
                $i += self::amPmLength($parts, $i, $count) - 1;
                $tokens[] = ['ampm', '1'];
            } else {
                self::addText($tokens, $text);
            }
        }

        $xml = '';
        $kinds = array_map(static fn (array $t): string => $t[0], $tokens);

        foreach ($tokens as $index => [$kind, $text]) {
            if ($kind === 'text') {
                $xml .= '<number:text>'.XmlEscaper::text($text).'</number:text>';

                continue;
            }

            $run = (int) $text;
            $long = $run >= 2 ? ' number:style="long"' : '';

            $xml .= match ($kind) {
                'y' => '<number:year'.($run >= 3 ? ' number:style="long"' : '').'/>',
                'd' => $run >= 3 ? '<number:day-of-week'.($run >= 4 ? ' number:style="long"' : '').'/>' : '<number:day'.$long.'/>',
                'h' => '<number:hours'.$long.'/>',
                's' => '<number:seconds'.$long.'/>',
                'ampm' => '<number:am-pm/>',
                // "m" is minutes next to hours or seconds and months otherwise.
                default => self::isMinutes($kinds, $index)
                    ? '<number:minutes'.$long.'/>'
                    : ($run >= 3 ? '<number:month number:textual="true"'.($run >= 4 ? ' number:style="long"' : '').'/>' : '<number:month'.$long.'/>'),
            };
        }

        return '<number:date-style>'.$xml.'</number:date-style>';
    }

    /**
     * Appends literal text, joining it to a literal that precedes it.
     *
     * @param  list<array{0: string, 1: string}>  $tokens
     */
    private static function addText(array &$tokens, string $text): void
    {
        $last = count($tokens) - 1;

        if ($last >= 0 && $tokens[$last][0] === 'text') {
            $tokens[$last][1] .= $text;
        } else {
            $tokens[] = ['text', $text];
        }
    }

    /**
     * @param  list<string>  $kinds
     */
    private static function isMinutes(array $kinds, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            if ($kinds[$i] !== 'text') {
                if ($kinds[$i] === 'h') {
                    return true;
                }

                break;
            }
        }

        for ($i = $index + 1; $i < count($kinds); $i++) {
            if ($kinds[$i] !== 'text') {
                return $kinds[$i] === 's';
            }
        }

        return false;
    }
}
