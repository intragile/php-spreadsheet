<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Xlsx;

use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use IntrAgile\Spreadsheet\Xlsx\ColumnName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

final class ColumnNameTest extends TestCase
{
    /**
     * @return iterable<string, array{string, int}>
     */
    public static function references(): iterable
    {
        yield 'first column' => ['A1', 0];
        yield 'last single letter' => ['Z9', 25];
        yield 'two letters' => ['AA7', 26];
        yield 'column only' => ['AB', 27];
        yield 'lowercase' => ['az3', 51];
        yield 'last column' => ['XFD1048576', 16383];
    }

    #[DataProvider('references')]
    public function test_to_index(string $reference, int $index): void
    {
        $this->assertSame($index, ColumnName::toIndex($reference));
    }

    public function test_column_name_roundtrip(): void
    {
        $this->assertSame('XFD', ColumnName::fromIndex(ColumnName::toIndex('XFD7')));

        for ($index = 0; $index < 1000; $index++) {
            $this->assertSame($index, ColumnName::toIndex(ColumnName::fromIndex($index).'1'));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidReferences(): iterable
    {
        yield 'empty' => [''];
        yield 'digits only' => ['12'];
        yield 'beyond XFD' => ['XFE1'];
        yield 'four letters' => ['AAAA1'];
        yield 'letters after digits' => ['A1B'];
    }

    #[DataProvider('invalidReferences')]
    public function test_invalid_references_throw(string $reference): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColumnName::toIndex($reference);
    }

    public function test_from_index_rejects_out_of_range(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ColumnName::fromIndex(16384);
    }
}
