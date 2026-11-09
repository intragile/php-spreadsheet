<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

use IntrAgile\Spreadsheet\Internal\Xml\XmlEscaper;
use IntrAgile\Spreadsheet\Style;
use WeakMap;

/**
 * Collects the distinct cell formats of a workbook being written and renders styles.xml.
 *
 * Every distinct combination of font, fill, border, alignment and number format becomes one
 * cell format (xf); equal styles share it however many Style instances describe them. Lookups
 * are cached per Style instance, so a style reused across many rows costs one hash lookup.
 *
 * @internal
 */
final class StyleRegistry
{
    /** Format applied to date cells whose style has no number format of its own. */
    public const DATE_FORMAT = 'yyyy-mm-dd';

    /** Format applied to date-time cells whose style has no number format of its own. */
    public const DATE_TIME_FORMAT = 'yyyy-mm-dd hh:mm:ss';

    /** First id available for custom number formats; lower ids are built in. */
    private const FIRST_CUSTOM_FORMAT = 164;

    private const DEFAULT_FONT = '<font><sz val="11"/><name val="Calibri"/><family val="2"/></font>';

    private const VARIANT_PLAIN = 0;

    private const VARIANT_DATE = 1;

    private const VARIANT_DATE_TIME = 2;

    /** @var array<string, int> font XML => font id */
    private array $fonts = [self::DEFAULT_FONT => 0];

    /** @var array<string, int> fill XML => fill id; the first two fills are reserved by the format */
    private array $fills = [
        '<fill><patternFill patternType="none"/></fill>' => 0,
        '<fill><patternFill patternType="gray125"/></fill>' => 1,
    ];

    /** @var array<string, int> border XML => border id */
    private array $borders = ['<border><left/><right/><top/><bottom/><diagonal/></border>' => 0];

    /** @var array<string, int> format code => numFmtId */
    private array $numberFormats = [];

    /** @var array<string, int> xf XML => xf index */
    private array $cellFormats = ['<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' => 0];

    /** @var WeakMap<Style, array<int, int>> style => variant => xf index */
    private WeakMap $cache;

    /** @var array<int, int> variant => xf index for cells without a style */
    private array $unstyled = [self::VARIANT_PLAIN => 0];

    public function __construct()
    {
        $this->cache = new WeakMap;
    }

    /**
     * Cell format index for a style; 0 is the default format.
     *
     * @param  bool  $isDate  the cell holds a date serial; without a number format of its own the style gets a date format
     * @param  bool  $hasTime  the date has a time of day, so the default format includes the time
     */
    public function idFor(?Style $style, bool $isDate = false, bool $hasTime = false): int
    {
        $variant = $isDate ? ($hasTime ? self::VARIANT_DATE_TIME : self::VARIANT_DATE) : self::VARIANT_PLAIN;

        if ($style === null) {
            return $this->unstyled[$variant] ??= $this->register(null, $variant);
        }

        $ids = $this->cache[$style] ?? [];

        if (isset($ids[$variant])) {
            return $ids[$variant];
        }

        $ids[$variant] = $this->register($style, $variant);
        $this->cache[$style] = $ids;

        return $ids[$variant];
    }

    public function toXml(): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if ($this->numberFormats !== []) {
            $xml .= '<numFmts count="'.count($this->numberFormats).'">';

            foreach ($this->numberFormats as $code => $id) {
                $xml .= '<numFmt numFmtId="'.$id.'" formatCode="'.XmlEscaper::text((string) $code).'"/>';
            }

            $xml .= '</numFmts>';
        }

        return $xml
            .self::collection('fonts', $this->fonts)
            .self::collection('fills', $this->fills)
            .self::collection('borders', $this->borders)
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .self::collection('cellXfs', $this->cellFormats)
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'<dxfs count="0"/>'
            .'</styleSheet>';
    }

    private function register(?Style $style, int $variant): int
    {
        $code = $style?->getNumberFormat();

        if ($code === null && $variant !== self::VARIANT_PLAIN) {
            $code = $variant === self::VARIANT_DATE_TIME ? self::DATE_TIME_FORMAT : self::DATE_FORMAT;
        }

        $numFmtId = $code === null || $code === 'General' ? 0 : $this->numberFormatId($code);
        $fontId = $style === null ? 0 : $this->fontId($style);
        $fillId = $style?->getFill() === null ? 0 : self::idOf($this->fills,
            '<fill><patternFill patternType="solid"><fgColor rgb="FF'.$style->getFill().'"/><bgColor indexed="64"/></patternFill></fill>');
        $borderId = 0;

        if ($style?->getBorder() !== null) {
            $side = ' style="thin"><color rgb="FF'.$style->getBorder().'"/>';
            $borderId = self::idOf($this->borders,
                '<border><left'.$side.'</left><right'.$side.'</right><top'.$side.'</top><bottom'.$side.'</bottom><diagonal/></border>');
        }

        $alignment = '';

        if ($style?->getAlign() !== null) {
            $alignment .= ' horizontal="'.$style->getAlign().'"';
        }

        if ($style?->isWrapped() === true) {
            $alignment .= ' wrapText="1"';
        }

        $xf = '<xf numFmtId="'.$numFmtId.'" fontId="'.$fontId.'" fillId="'.$fillId.'" borderId="'.$borderId.'" xfId="0"'
            .($numFmtId !== 0 ? ' applyNumberFormat="1"' : '')
            .($fontId !== 0 ? ' applyFont="1"' : '')
            .($fillId !== 0 ? ' applyFill="1"' : '')
            .($borderId !== 0 ? ' applyBorder="1"' : '')
            .($alignment !== '' ? ' applyAlignment="1"><alignment'.$alignment.'/></xf>' : '/>');

        return self::idOf($this->cellFormats, $xf);
    }

    private function fontId(Style $style): int
    {
        if (! $style->isBold() && ! $style->isItalic() && $style->getFontColor() === null) {
            return 0;
        }

        return self::idOf($this->fonts, '<font>'
            .($style->isBold() ? '<b/>' : '')
            .($style->isItalic() ? '<i/>' : '')
            .'<sz val="11"/>'
            .($style->getFontColor() !== null ? '<color rgb="FF'.$style->getFontColor().'"/>' : '')
            .'<name val="Calibri"/><family val="2"/></font>');
    }

    private function numberFormatId(string $code): int
    {
        return $this->numberFormats[$code] ??= self::FIRST_CUSTOM_FORMAT + count($this->numberFormats);
    }

    /**
     * @param  array<string, int>  $entries
     */
    private static function idOf(array &$entries, string $xml): int
    {
        return $entries[$xml] ??= count($entries);
    }

    /**
     * @param  array<string, int>  $entries  XML => id, in id order
     */
    private static function collection(string $element, array $entries): string
    {
        return '<'.$element.' count="'.count($entries).'">'.implode('', array_keys($entries)).'</'.$element.'>';
    }
}
