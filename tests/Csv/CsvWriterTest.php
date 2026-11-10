<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Csv;

use DateTimeImmutable;
use Generator;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Csv\CsvReader;
use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Style;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

final class CsvWriterTest extends TestCase
{
    public function test_roundtrip_with_reader(): void
    {
        $path = Fixtures::path('csv');
        $rows = [
            ['id', 'text'],
            ['1', 'plain'],
            ['2', 'has "quotes" inside'],
            ['3', "two\nlines"],
            ['4', 'semi;colon, comma'],
            ['5', 'Árvíztűrő tükörfúrógép'],
        ];

        $writer = new CsvWriter($path, ';');
        $writer->addRows($rows)->close();

        $read = (new CsvReader($path, new ReadOptions(csvDelimiter: ';')))->rows();

        $this->assertSame($rows, array_values(iterator_to_array($read)));
    }

    public function test_bom_option_writes_efbbbf(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path, ',', true))->addRow(['a', 'b'])->close();

        $this->assertSame("\xEF\xBB\xBFa,b\r\n", file_get_contents($path));
    }

    public function test_no_bom_by_default(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path))->addRow(['a'])->close();

        $this->assertSame("a\r\n", file_get_contents($path));
    }

    public function test_second_sheet_throws(): void
    {
        $writer = new CsvWriter(Fixtures::path('csv'));
        $writer->sheet('One', new SheetOptions);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CSV supports one sheet');

        $writer->sheet('Two');
    }

    public function test_sheet_after_implicit_sheet_throws(): void
    {
        $writer = new CsvWriter(Fixtures::path('csv'));
        $writer->addRow(['a']);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CSV supports one sheet');

        $writer->sheet('Late');
    }

    public function test_close_is_idempotent_and_atomic(): void
    {
        $path = Fixtures::path('csv');
        $writer = new CsvWriter($path);
        $writer->addRow(['a', 'b']);

        $this->assertFileDoesNotExist($path);

        $writer->close();
        $this->assertFileExists($path);

        $writer->close();
        $this->assertSame("a,b\r\n", file_get_contents($path));
    }

    public function test_close_without_rows_creates_empty_file(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path))->close();

        $this->assertSame('', file_get_contents($path));
    }

    public function test_write_after_close_throws(): void
    {
        $writer = new CsvWriter(Fixtures::path('csv'));
        $writer->close();

        $this->expectException(LogicException::class);

        $writer->addRow(['a']);
    }

    public function test_value_mapping(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path))->addRow([
            1,
            true,
            false,
            null,
            0.1,
            1.5e20,
            new DateTimeImmutable('2024-03-05 00:00:00'),
            new DateTimeImmutable('2024-03-05 13:14:15'),
            '00123',
            Cell::text('=1+1'),
            Cell::formula('=A1*2'),
            Cell::of(2.5, Style::make()->bold()),
        ], Style::make()->bold())->close();

        $this->assertSame(
            "1,1,0,,0.1,1.5E+20,2024-03-05,\"2024-03-05 13:14:15\",00123,=1+1,=A1*2,2.5\r\n",
            file_get_contents($path)
        );
    }

    public function test_float_mapping_ignores_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'hu_HU.UTF-8', 'hu_HU', 'de_DE.UTF-8');

        try {
            $path = Fixtures::path('csv');
            (new CsvWriter($path))->addRow([1234.5, 0.30000000000000004])->close();
        } finally {
            setlocale(LC_NUMERIC, $previous === false ? 'C' : $previous);
        }

        $this->assertSame("1234.5,0.30000000000000004\r\n", file_get_contents($path));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unwritableValues(): iterable
    {
        yield 'infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
        yield 'array' => [['nested']];
        yield 'object' => [new \stdClass];
    }

    #[DataProvider('unwritableValues')]
    public function test_values_xlsx_and_ods_refuse_are_refused(mixed $value): void
    {
        $path = Fixtures::path('csv');
        $writer = new CsvWriter($path);

        try {
            /** @phpstan-ignore argument.type (deliberately invalid input) */
            $writer->addRow(['ok', $value]);
            $this->fail('The value was written.');
        } catch (InvalidArgumentException) {
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_formulas_are_escaped_when_asked(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path, escapeFormulas: true))
            ->addRow(['=1+1', '+36 1 234 5678', '-5', '@SUM(A1)', "\tx", "\rx", 'plain', -5, Cell::text('=HYPERLINK("x")'), Cell::formula('=A1')])
            ->close();

        $this->assertSame("'=1+1,\"'+36 1 234 5678\",'-5,'@SUM(A1),\"'\tx\",\"'\rx\",plain,-5,\"'=HYPERLINK(\"\"x\"\")\",=A1\r\n", file_get_contents($path));
    }

    public function test_formulas_are_kept_verbatim_by_default(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path))->addRow(['=1+1', '-5'])->close();

        $this->assertSame("=1+1,-5\r\n", file_get_contents($path));
    }

    public function test_exception_while_writing_leaves_no_files(): void
    {
        $dir = sys_get_temp_dir().'/sps_cw_'.bin2hex(random_bytes(4));
        mkdir($dir);
        $path = $dir.'/out.csv';

        try {
            $writer = new CsvWriter($path);
            $rows = (function (): Generator {
                yield ['ok'];

                throw new RuntimeException('boom');
            })();

            try {
                $writer->addRows($rows);
                $this->fail('Expected exception');
            } catch (RuntimeException) {
            }

            $writer->close();

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_destruct_without_close_removes_temp_and_never_creates_target(): void
    {
        $dir = sys_get_temp_dir().'/sps_cw_'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            $writer = new CsvWriter($dir.'/out.csv');
            $writer->addRow(['a']);
            $this->assertCount(1, glob($dir.'/*') ?: []);

            unset($writer);

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            array_map('unlink', glob($dir.'/*') ?: []);
            rmdir($dir);
        }
    }

    public function test_final_file_has_umask_permissions(): void
    {
        $path = Fixtures::path('csv');
        (new CsvWriter($path))->addRow(['a'])->close();

        clearstatcache();
        $this->assertSame(0666 & ~umask(), fileperms($path) & 0777);
    }

    public function test_missing_target_directory_throws_naming_it(): void
    {
        $writer = new CsvWriter('/nonexistent-sps-dir/out.csv');

        $this->expectException(SpreadsheetException::class);
        $this->expectExceptionMessage('/nonexistent-sps-dir');

        $writer->addRow(['a']);
    }

    public function test_memory_flat(): void
    {
        $small = $this->writeRows(30_000);
        $large = $this->writeRows(300_000);

        $this->assertLessThan(4 * 1024 * 1024, $large - $small);
    }

    private function writeRows(int $count): int
    {
        $path = Fixtures::path('csv');

        return self::peakGrowth(function () use ($path, $count): void {
            $writer = new CsvWriter($path);

            for ($i = 0; $i < $count; $i++) {
                $writer->addRow([$i, 'name '.$i, $i * 1.5, true]);
            }

            $writer->close();
        });
    }
}
