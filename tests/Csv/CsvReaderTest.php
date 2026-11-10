<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Csv;

use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Csv\CsvReader;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class CsvReaderTest extends TestCase
{
    public function test_hungarian_excel_export(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/hu-excel-1250.csv'), new ReadOptions);
        $rows = iterator_to_array($reader->rows());

        $this->assertSame(['Név', 'Összeg', 'Város'], $rows[1]);
        $this->assertSame(['Árvíztűrő tükörfúrógép', '1 234,56', 'Győr'], $rows[2]);
        $this->assertSame(['Őrmester Ügyfél', '99,5', 'Szőlős'], $rows[3]);
    }

    public function test_utf8_bom_is_removed(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions))->rows());

        $this->assertSame('id', $rows[1][0]);
        $this->assertSame(['2', 'Bob'], $rows[3]);
    }

    public function test_utf16le_is_decoded(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::committed('csv/utf16le-tab.txt'), new ReadOptions))->rows());

        $this->assertSame(['id', 'name'], $rows[1]);
        $this->assertSame(['1', 'Árpád'], $rows[2]);
    }

    public function test_quoted_newline_stays_in_cell(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::committed('csv/quoted-multiline.csv'), new ReadOptions))->rows());

        $this->assertSame(['1', "line one\nline two"], $rows[2]);
        $this->assertSame([1, 2, 3], array_keys($rows));
    }

    public function test_manual_override_wins(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/hu-excel-1250.csv'), new ReadOptions(csvDelimiter: ','));
        $rows = iterator_to_array($reader->rows());

        $this->assertCount(1, $rows[1]);
        $this->assertSame('Név;Összeg;Város', $rows[1][0]);
    }

    public function test_encoding_override_wins(): void
    {
        $path = Fixtures::file(mb_convert_encoding("a;b\nő;1\n", 'ISO-8859-2', 'UTF-8'), 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions(csvEncoding: 'ISO-8859-2')))->rows());

        $this->assertSame(['ő', '1'], $rows[2]);
    }

    public function test_iso_8859_2_is_reachable_through_the_override(): void
    {
        $rows = iterator_to_array((new CsvReader(Fixtures::file("a;b\n\xF5;1\n", 'csv'), new ReadOptions(csvEncoding: 'ISO-8859-2')))->rows());

        $this->assertSame(['ő', '1'], $rows[2]);
    }

    public function test_unmappable_windows_1250_byte_becomes_a_replacement_character(): void
    {
        // 0x81 has no character in Windows-1250; iconv used to drop every row of the chunk.
        $lines = ["N\xE9v;\xD6sszeg"];

        for ($i = 1; $i <= 2000; $i++) {
            $lines[] = $i === 1000 ? "Gy\xF5r\x81;{$i}" : "\xC1rv\xEDzt\xFBr\xF5 {$i};{$i}";
        }

        $path = Fixtures::file(implode("\r\n", $lines)."\r\n", 'csv');

        $rows = self::withoutPhpErrors(static fn (): array => iterator_to_array((new CsvReader($path, new ReadOptions))->rows()));

        $this->assertCount(2001, $rows);
        $this->assertSame(['Név', 'Összeg'], $rows[1]);
        $this->assertSame(["Győr\u{FFFD}", '1000'], $rows[1001]);
        $this->assertSame(['Árvíztűrő 2000', '2000'], $rows[2001]);
    }

    public function test_utf16_surrogate_pairs_and_odd_bytes_survive_chunk_boundaries(): void
    {
        $expected = [1 => ['id', 'name']];
        $text = "id\tname\n";

        for ($i = 1; $i <= 3000; $i++) {
            $name = str_repeat('é', $i % 7)."😀 Árpád {$i}";
            $text .= "{$i}\t{$name}\n";
            $expected[$i + 1] = [(string) $i, $name];
        }

        foreach (['UTF-16LE' => "\xFF\xFE", 'UTF-16BE' => "\xFE\xFF"] as $encoding => $bom) {
            // A stray trailing byte cannot form a code unit and is replaced.
            $path = Fixtures::file($bom.mb_convert_encoding($text.'x', $encoding, 'UTF-8')."\x00", 'txt');

            $rows = self::withoutPhpErrors(static fn (): array => iterator_to_array((new CsvReader($path, new ReadOptions))->rows()));

            $this->assertSame($expected + [3002 => ["x\u{FFFD}"]], $rows, $encoding);
        }
    }

    /** @return iterable<string, array{string|null, string, string}> */
    public static function encodingsWithByteOrderMarks(): iterable
    {
        yield 'UTF-16 named, LE mark' => ['UTF-16', 'UTF-16LE', "\xFF\xFE"];
        yield 'UTF-16 named, BE mark' => ['UTF-16', 'UTF-16BE', "\xFE\xFF"];
        yield 'UCS-2 named, LE mark' => ['UCS-2', 'UTF-16LE', "\xFF\xFE"];
        yield 'UCS-2LE named, LE mark' => ['UCS-2LE', 'UTF-16LE', "\xFF\xFE"];
        yield 'utf-16 lowercase, LE mark' => ['utf-16', 'UTF-16LE', "\xFF\xFE"];
        yield 'UTF-32 named, LE mark' => ['UTF-32', 'UTF-32LE', "\xFF\xFE\x00\x00"];
        yield 'UTF-32 named, BE mark' => ['UTF-32', 'UTF-32BE', "\x00\x00\xFE\xFF"];
        yield 'UCS-4 named, LE mark' => ['UCS-4', 'UTF-32LE', "\xFF\xFE\x00\x00"];
        yield 'detected UTF-32LE mark' => [null, 'UTF-32LE', "\xFF\xFE\x00\x00"];
        yield 'detected UTF-32BE mark' => [null, 'UTF-32BE', "\x00\x00\xFE\xFF"];
    }

    #[DataProvider('encodingsWithByteOrderMarks')]
    public function test_the_byte_order_mark_decides_when_the_encoding_has_no_byte_order(?string $named, string $actual, string $bom): void
    {
        $path = Fixtures::file($bom.mb_convert_encoding("id\tname\n1\tÁrpád 😀\n", $actual, 'UTF-8'), 'txt');

        $rows = self::withoutPhpErrors(static fn (): array => iterator_to_array((new CsvReader($path, new ReadOptions(csvEncoding: $named)))->rows()));

        $this->assertSame([1 => ['id', 'name'], 2 => ['1', 'Árpád 😀']], $rows);
    }

    /** @return iterable<string, array{string}> */
    public static function unicodeWithoutMarks(): iterable
    {
        yield 'UTF-16LE' => ['UTF-16LE'];
        yield 'UTF-16BE' => ['UTF-16BE'];
        yield 'UTF-32LE' => ['UTF-32LE'];
        yield 'UTF-32BE' => ['UTF-32BE'];
    }

    #[DataProvider('unicodeWithoutMarks')]
    public function test_utf16_and_utf32_without_a_mark_are_detected(string $encoding): void
    {
        $text = "Név;Összeg;Város\nÁrvíztűrő tükörfúrógép;1 234,56;Győr\nŐrmester Ügyfél;99,5;Szőlős\n";
        $path = Fixtures::file(mb_convert_encoding($text, $encoding, 'UTF-8'), 'csv');

        $rows = self::withoutPhpErrors(static fn (): array => iterator_to_array((new CsvReader($path, new ReadOptions))->rows()));

        $this->assertSame([
            1 => ['Név', 'Összeg', 'Város'],
            2 => ['Árvíztűrő tükörfúrógép', '1 234,56', 'Győr'],
            3 => ['Őrmester Ügyfél', '99,5', 'Szőlős'],
        ], $rows);
    }

    public function test_an_encoding_without_byte_order_and_without_a_mark_is_big_endian(): void
    {
        foreach (['UTF-16' => 'UTF-16BE', 'UTF-32' => 'UTF-32BE'] as $named => $actual) {
            $path = Fixtures::file(mb_convert_encoding("id;name\n1;Árpád\n", $actual, 'UTF-8'), 'csv');

            $rows = iterator_to_array((new CsvReader($path, new ReadOptions(csvEncoding: $named)))->rows());

            $this->assertSame([1 => ['id', 'name'], 2 => ['1', 'Árpád']], $rows, $named);
        }
    }

    public function test_unsupported_encoding_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported CSV encoding "X-NONSENSE"');

        iterator_to_array((new CsvReader(Fixtures::file("a\n", 'csv'), new ReadOptions(csvEncoding: 'X-NONSENSE')))->rows());
    }

    public function test_a_stray_quote_cannot_swallow_the_rest_of_the_file(): void
    {
        $path = Fixtures::path('csv');
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);
        fwrite($handle, "a,b\n1,\"unterminated\n");

        for ($i = 0; $i < 40_000; $i++) {
            fwrite($handle, str_repeat('x,', 50)."\n");
        }

        fclose($handle);

        self::withoutPhpErrors(function () use ($path): void {
            $rows = 0;
            $growth = self::peakGrowth(function () use ($path, &$rows): void {
                try {
                    foreach ((new CsvReader($path, new ReadOptions))->rows() as $row) {
                        $rows++;
                    }

                    $this->fail('The unterminated field was accepted.');
                } catch (LimitExceeded $exception) {
                    $this->assertStringContainsString('1048576 bytes', $exception->getMessage());
                }
            });

            $this->assertLessThan(8 * 1024 * 1024, $growth);
        });
    }

    /** @return iterable<string, array{string}> */
    public static function blanksBeforeAnEnclosure(): iterable
    {
        yield 'form feed' => ["\f"];
        yield 'vertical tab' => ["\v"];
        yield 'carriage return' => ["\r"];
        yield 'space and tab' => [" \t"];
    }

    #[DataProvider('blanksBeforeAnEnclosure')]
    public function test_any_blank_before_a_stray_quote_is_bounded(string $blank): void
    {
        $path = Fixtures::file("a,b\n1,{$blank}\"unterminated\n".str_repeat(str_repeat('x,', 50)."\n", 200), 'csv');

        self::withoutPhpErrors(function () use ($path): void {
            $this->expectException(LimitExceeded::class);
            $this->expectExceptionMessage('64 bytes');

            iterator_to_array((new CsvReader($path, new ReadOptions(csvDelimiter: ',', maxCellBytes: 64)))->rows());
        });
    }

    #[DataProvider('blanksBeforeAnEnclosure')]
    public function test_a_quoted_field_after_blanks_is_read_like_fgetcsv(string $blank): void
    {
        $path = Fixtures::file("a,b\n1,{$blank}\"x,y\"\n2,z\n", 'csv');

        $rows = iterator_to_array((new CsvReader($path, new ReadOptions(csvDelimiter: ',', maxCellBytes: 64)))->rows());

        $this->assertSame([1 => ['a', 'b'], 2 => ['1', 'x,y'], 3 => ['2', 'z']], $rows);
    }

    public function test_a_record_with_more_than_16384_fields_is_refused(): void
    {
        $ok = Fixtures::file(str_repeat(',', 16_383)."1\n", 'csv');
        $this->assertCount(16_384, iterator_to_array((new CsvReader($ok, new ReadOptions))->rows())[1]);

        $path = Fixtures::file("a\n".str_repeat(',', 16_384)."1\n", 'csv');

        self::withoutPhpErrors(function () use ($path): void {
            $this->expectException(LimitExceeded::class);
            $this->expectExceptionMessage('16384 fields');

            iterator_to_array((new CsvReader($path, new ReadOptions(csvDelimiter: ',')))->rows());
        });
    }

    public function test_field_limit_is_configurable_and_counts_quoted_text(): void
    {
        $path = Fixtures::file("id,text\n1,\"ab\"\"c\nde\"\n2,ok\n", 'csv');

        $this->assertSame(['1', "ab\"c\nde"], iterator_to_array((new CsvReader($path, new ReadOptions(maxCellBytes: 12)))->rows())[2]);

        $this->expectException(LimitExceeded::class);

        iterator_to_array((new CsvReader($path, new ReadOptions(maxCellBytes: 6)))->rows());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function smallFieldLimits(): iterable
    {
        yield '64 bytes' => [64];
        yield '1 KiB' => [1024];
        yield '4 KiB' => [4096];
    }

    #[DataProvider('smallFieldLimits')]
    public function test_a_field_limit_below_the_read_chunk_is_exact_at_every_offset(int $limit): void
    {
        // Short quoted records spanning many read chunks, with one field of exactly $limit raw bytes
        // (enclosures included) placed at shifting offsets, so it straddles chunk and window edges.
        $filler = str_repeat("1,\"a, b\",\"c \"\"d\"\"\"\n", 700);

        foreach ([0, 1, 7, 13] as $shift) {
            $prefix = $filler.str_repeat("x\n", $shift);
            $fits = Fixtures::file($prefix.'2,"'.str_repeat('y', $limit - 2)."\"\n3,z\n", 'csv');
            $rows = iterator_to_array((new CsvReader($fits, new ReadOptions(maxCellBytes: $limit)))->rows(), false);

            $this->assertCount(700 + $shift + 2, $rows);
            $this->assertSame(['2', str_repeat('y', $limit - 2)], $rows[700 + $shift]);

            $tooLong = Fixtures::file($prefix.'2,"'.str_repeat('y', $limit - 1)."\"\n3,z\n", 'csv');

            try {
                iterator_to_array((new CsvReader($tooLong, new ReadOptions(maxCellBytes: $limit)))->rows());
                $this->fail("A field of {$limit} + 1 bytes must be refused (shift {$shift}).");
            } catch (LimitExceeded $e) {
                $this->assertStringContainsString("{$limit} bytes", $e->getMessage());
            }
        }
    }

    public function test_quotes_inside_unquoted_fields_are_literal_for_the_field_limit(): void
    {
        $lines = ['item,size'];

        for ($i = 0; $i < 5000; $i++) {
            $lines[] = "pipe {$i},5\" wide";
        }

        $path = Fixtures::file(implode("\n", $lines)."\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions(maxCellBytes: 20)))->rows());

        $this->assertCount(5001, $rows);
        $this->assertSame(['pipe 4999', '5" wide'], $rows[5001]);
    }

    public function test_resuming_rows_after_close_throws(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);
        $rows = $reader->rows();
        $rows->current();
        $reader->close();

        $this->expectException(LogicException::class);

        $rows->next();
    }

    public function test_concurrent_generators_are_independent_and_close_releases_all(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);
        $streamsBefore = count(get_resources('stream'));
        $first = $reader->rows();
        $second = $reader->rows();

        $this->assertSame(['id', 'name'], $first->current());
        $this->assertSame(['id', 'name'], $second->current());
        $first->next();
        $this->assertSame(['1', 'Alice'], $first->current());
        $this->assertSame(['id', 'name'], $second->current());
        $this->assertSame($streamsBefore + 2, count(get_resources('stream')));

        $reader->close();

        $this->assertSame($streamsBefore, count(get_resources('stream')), 'close() must release every open handle');

        foreach ([$first, $second] as $generator) {
            try {
                $generator->next();
                $this->fail('A generator must not keep reading after close().');
            } catch (LogicException $e) {
                $this->assertStringContainsString('closed', $e->getMessage());
            }
        }
    }

    public function test_empty_lines_skipped_and_row_numbers_preserved(): void
    {
        $path = Fixtures::file("a,b\n1,2\n\n3,4\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rows());

        $this->assertSame([1, 2, 4], array_keys($rows));
    }

    public function test_rows_of_only_empty_cells_are_skipped(): void
    {
        $path = Fixtures::file("a,b\n,\n1,2\n", 'csv');

        $this->assertSame([1, 3], array_keys(iterator_to_array((new CsvReader($path, new ReadOptions))->rows())));
    }

    public function test_values_are_strings(): void
    {
        $path = Fixtures::file("id,v\n007,1e5\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rows());

        $this->assertSame(['007', '1e5'], $rows[2]);
    }

    public function test_rows_with_header_dedupes(): void
    {
        $path = Fixtures::file("a, a ,\n1,2,3\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rowsWithHeader());

        $this->assertSame([2], array_keys($rows));
        $this->assertSame(['a', 'a_2', 'column_3'], array_keys($rows[2]));
        $this->assertSame(['a' => '1', 'a_2' => '2', 'column_3' => '3'], $rows[2]);
    }

    public function test_rows_with_header_pads_short_rows_and_names_extra_cells(): void
    {
        $path = Fixtures::file("a,b\n1\n1,2,3\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rowsWithHeader());

        $this->assertSame(['a' => '1', 'b' => null], $rows[2]);
        $this->assertSame(['a' => '1', 'b' => '2', 'column_3' => '3'], $rows[3]);
    }

    public function test_rows_with_header_on_a_later_header_row(): void
    {
        $path = Fixtures::file("title\nx,y\n1,2\n", 'csv');
        $rows = iterator_to_array((new CsvReader($path, new ReadOptions))->rowsWithHeader(0, 2));

        $this->assertSame([3 => ['x' => '1', 'y' => '2']], $rows);
    }

    public function test_sheets(): void
    {
        $sheets = (new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions))->sheets();

        $this->assertCount(1, $sheets);
        $this->assertSame('Sheet1', $sheets[0]->name);
        $this->assertSame(0, $sheets[0]->index);
    }

    public function test_sheet_selector_accepts_zero_and_sheet1_only(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);

        $this->assertNotEmpty(iterator_to_array($reader->rows('Sheet1')));

        $this->expectException(InvalidArgumentException::class);
        $reader->rows(1);
    }

    public function test_unknown_sheet_name_is_rejected_for_header_rows_too(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions))->rowsWithHeader('Other');
    }

    public function test_missing_file(): void
    {
        $this->expectException(SpreadsheetException::class);
        new CsvReader('/nonexistent/file.csv', new ReadOptions);
    }

    public function test_implements_reader_and_close_is_idempotent(): void
    {
        $reader = new CsvReader(Fixtures::committed('csv/utf8-bom-comma.csv'), new ReadOptions);

        $this->assertInstanceOf(Reader::class, $reader);
        $reader->close();
        $reader->close();
        $this->assertNotEmpty(iterator_to_array($reader->rows()));
    }

    public function test_memory_flat(): void
    {
        $small = $this->writeRows(30_000);
        $large = $this->writeRows(300_000);

        $smallGrowth = self::peakGrowth(fn () => $this->consume($small));
        $largeGrowth = self::peakGrowth(fn () => $this->consume($large));

        $this->assertLessThan(4 * 1024 * 1024, $largeGrowth - $smallGrowth);
    }

    private function consume(string $path): void
    {
        $count = 0;

        foreach ((new CsvReader($path, new ReadOptions))->rows() as $row) {
            $count += count($row);
        }

        $this->assertGreaterThan(0, $count);
    }

    private function writeRows(int $count): string
    {
        $path = Fixtures::path('csv');
        $handle = fopen($path, 'wb');
        $this->assertIsResource($handle);

        for ($i = 1; $i <= $count; $i++) {
            fwrite($handle, "{$i};Árvíztűrő tükörfúrógép {$i};\"quoted; value\";".($i % 97).",5\n");
        }

        fclose($handle);

        return $path;
    }
}
