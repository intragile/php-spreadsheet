<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests;

use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ReadOptionsTest extends TestCase
{
    public function test_defaults(): void
    {
        $options = new ReadOptions;

        $this->assertSame(1_048_576, $options->maxCellBytes);
        $this->assertSame(100_000_000, $options->maxCells);
        $this->assertSame(10_000_000, $options->maxSharedStrings);
    }

    public function test_existing_positional_arguments_keep_their_meaning(): void
    {
        $options = new ReadOptions(';', 'UTF-8', "'", 5, 6, 7, 8);

        $this->assertSame([';', 'UTF-8', "'", 5, 6, 7, 8], [
            $options->csvDelimiter, $options->csvEncoding, $options->csvEnclosure, $options->sharedStringsInMemory,
            $options->maxZipEntries, $options->maxUncompressedBytes, $options->maxCompressionRatio,
        ]);
    }

    public function test_zero_shared_strings_in_memory_is_allowed(): void
    {
        $this->assertSame(0, (new ReadOptions(sharedStringsInMemory: 0))->sharedStringsInMemory);
    }

    /**
     * @return iterable<string, array{string, callable(): ReadOptions}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'negative shared strings in memory' => ['sharedStringsInMemory', static fn (): ReadOptions => new ReadOptions(sharedStringsInMemory: -1)];
        yield 'zero zip entries' => ['maxZipEntries', static fn (): ReadOptions => new ReadOptions(maxZipEntries: 0)];
        yield 'zero uncompressed bytes' => ['maxUncompressedBytes', static fn (): ReadOptions => new ReadOptions(maxUncompressedBytes: 0)];
        yield 'zero compression ratio' => ['maxCompressionRatio', static fn (): ReadOptions => new ReadOptions(maxCompressionRatio: 0)];
        yield 'zero cell bytes' => ['maxCellBytes', static fn (): ReadOptions => new ReadOptions(maxCellBytes: 0)];
        yield 'negative cells' => ['maxCells', static fn (): ReadOptions => new ReadOptions(maxCells: -5)];
        yield 'zero shared strings' => ['maxSharedStrings', static fn (): ReadOptions => new ReadOptions(maxSharedStrings: 0)];
    }

    /**
     * @param  callable(): ReadOptions  $create
     */
    #[DataProvider('invalidLimits')]
    public function test_limits_must_be_positive(string $name, callable $create): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($name);

        $create();
    }
}
