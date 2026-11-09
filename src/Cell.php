<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

use DateTimeInterface;
use InvalidArgumentException;

/**
 * An explicit cell for writers: text that must never be coerced, a formula, or a styled value.
 */
final class Cell
{
    public const KIND_TEXT = 'text';

    public const KIND_FORMULA = 'formula';

    public const KIND_VALUE = 'value';

    /**
     * @param  self::KIND_*  $kind
     */
    private function __construct(
        public readonly int|float|string|bool|DateTimeInterface|null $value,
        public readonly ?Style $style,
        public readonly string $kind,
    ) {}

    /**
     * Always written as a string, even when it looks like a number or a formula.
     */
    public static function text(string $value, ?Style $style = null): self
    {
        return new self($value, $style, self::KIND_TEXT);
    }

    /**
     * @param  string  $formula  must start with "="
     */
    public static function formula(string $formula, ?Style $style = null): self
    {
        if (! str_starts_with($formula, '=')) {
            throw new InvalidArgumentException('A formula must start with "=".');
        }

        return new self($formula, $style, self::KIND_FORMULA);
    }

    public static function of(int|float|string|bool|DateTimeInterface|null $value, Style $style): self
    {
        return new self($value, $style, self::KIND_VALUE);
    }
}
