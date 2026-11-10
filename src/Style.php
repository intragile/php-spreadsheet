<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

use InvalidArgumentException;

/**
 * Immutable cell style. Withers return clones, and equal styles produce equal keys so
 * writers can register each distinct style once.
 */
final class Style
{
    private bool $bold = false;

    private bool $italic = false;

    private ?string $fontColor = null;

    private ?string $fill = null;

    private ?string $border = null;

    private ?string $align = null;

    private bool $wrap = false;

    private ?string $numberFormat = null;

    private function __construct() {}

    public static function make(): self
    {
        return new self;
    }

    public function bold(bool $on = true): self
    {
        $clone = clone $this;
        $clone->bold = $on;

        return $clone;
    }

    public function italic(bool $on = true): self
    {
        $clone = clone $this;
        $clone->italic = $on;

        return $clone;
    }

    public function fontColor(string $hexRgb): self
    {
        $clone = clone $this;
        $clone->fontColor = self::hex($hexRgb);

        return $clone;
    }

    public function fill(string $hexRgb): self
    {
        $clone = clone $this;
        $clone->fill = self::hex($hexRgb);

        return $clone;
    }

    /**
     * Thin border on all four sides.
     */
    public function border(string $hexRgb = '000000'): self
    {
        $clone = clone $this;
        $clone->border = self::hex($hexRgb);

        return $clone;
    }

    /**
     * @param  string  $horizontal  left|center|right
     */
    public function align(string $horizontal): self
    {
        if (! in_array($horizontal, ['left', 'center', 'right'], true)) {
            throw new InvalidArgumentException("Unsupported alignment \"{$horizontal}\"; use left, center or right.");
        }

        $clone = clone $this;
        $clone->align = $horizontal;

        return $clone;
    }

    public function wrap(bool $on = true): self
    {
        $clone = clone $this;
        $clone->wrap = $on;

        return $clone;
    }

    /**
     * @throws InvalidArgumentException when the code is empty or not valid UTF-8
     */
    public function numberFormat(string $code): self
    {
        if ($code === '' || ! mb_check_encoding($code, 'UTF-8')) {
            throw new InvalidArgumentException('A number format must be a non-empty UTF-8 string.');
        }

        $clone = clone $this;
        $clone->numberFormat = $code;

        return $clone;
    }

    public function isBold(): bool
    {
        return $this->bold;
    }

    public function isItalic(): bool
    {
        return $this->italic;
    }

    public function getFontColor(): ?string
    {
        return $this->fontColor;
    }

    public function getFill(): ?string
    {
        return $this->fill;
    }

    public function getBorder(): ?string
    {
        return $this->border;
    }

    public function getAlign(): ?string
    {
        return $this->align;
    }

    public function isWrapped(): bool
    {
        return $this->wrap;
    }

    public function getNumberFormat(): ?string
    {
        return $this->numberFormat;
    }

    /**
     * Stable identity: two styles with the same settings have the same key.
     */
    public function key(): string
    {
        return json_encode([
            $this->bold, $this->italic, $this->fontColor, $this->fill, $this->border,
            $this->align, $this->wrap, $this->numberFormat,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function hex(string $value): string
    {
        if (preg_match('/^[0-9A-Fa-f]{6}$/', $value) !== 1) {
            throw new InvalidArgumentException("Invalid colour \"{$value}\"; expected six hexadecimal digits such as \"FFCC00\".");
        }

        return strtoupper($value);
    }
}
