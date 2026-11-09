<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Zip;

/**
 * Counts decompressed bytes against a limit.
 *
 * The sizes recorded in a zip's headers are supplied by whoever made the file and may be false,
 * so limits are enforced on the bytes that actually come out of the decompressor.
 *
 * @internal
 */
final class ByteBudget
{
    private bool $exceeded = false;

    public function __construct(private int $remaining) {}

    public function consume(int $bytes): bool
    {
        $this->remaining -= $bytes;

        if ($this->remaining < 0) {
            $this->exceeded = true;
        }

        return ! $this->exceeded;
    }

    public function isExceeded(): bool
    {
        return $this->exceeded;
    }

    public function remaining(): int
    {
        return max(0, $this->remaining);
    }
}
