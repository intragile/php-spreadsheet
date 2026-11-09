<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Ods;

use DateTimeImmutable;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Ods\OdsReader;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\OdsFixtureBuilder;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;

final class OdsReaderTest extends TestCase
{
    private const NS = 'xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"';

    public function test_types(): void
    {
        $rows = $this->read('libreoffice-mixed.ods');

        $this->assertSame(['Egész', 'Tört', 'Szöveg', 'Logikai', 'Dátum', 'Üres', 'Utolsó'], $rows[1]);
        $this->assertSame(42, $rows[2][0]);
        $this->assertSame(3.14, $rows[2][1]);
        $this->assertSame('szöveg', $rows[2][2]);
        $this->assertTrue($rows[2][3]);
        $this->assertEquals(new DateTimeImmutable('2025-01-31'), $rows[2][4]);
        $this->assertNull($rows[2][5]);
        $this->assertSame('utolsó', $rows[2][6]);
        $this->assertSame(
            [42, 3.14, 'szöveg', true, 'date:2025-01-31 00:00:00', null, 'utolsó'],
            self::normalize($rows[2]),
        );
    }

    public function test_percentage_currency_time_and_datetime(): void
    {
        $rows = $this->read('libreoffice-mixed.ods');

        // Times of day have no date to attach to, so the ISO 8601 duration is returned as is.
        $this->assertSame([0.125, 1500, 'PT13H45M00S', 'date:2025-01-31 13:45:00'], self::normalize($rows[3]));
    }

    public function test_text_joins_paragraphs_and_expands_spaces_tabs_and_breaks(): void
    {
        $rows = $this->read('libreoffice-mixed.ods');

        $this->assertSame("sor1\nsor2\n\nsor4", $rows[4][0]);
        $this->assertSame("a   b\tc d\ne", $rows[4][1]);
        $this->assertSame('Félkövér és dőlt <&>', $rows[4][2]);
    }

    public function test_annotations_do_not_leak_into_cells(): void
    {
        $rows = $this->read('libreoffice-mixed.ods');

        $this->assertSame('cella szöveg', $rows[4][3]);
        $this->assertSame(7, $rows[4][4]);
    }

    public function test_merged_and_covered_cells_keep_their_positions(): void
    {
        $rows = $this->read('libreoffice-mixed.ods');

        $this->assertSame(['összevont', null, 'x', 1.0E20, 'képlet'], $rows[5]);
    }

    public function test_covered_cell_with_a_value_is_returned(): void
    {
        $rows = $this->readXml('<table:table-row><table:table-cell/><table:covered-table-cell office:value-type="float" office:value="5"/></table:table-row>');

        $this->assertSame([1 => [null, 5]], $rows);
    }

    public function test_empty_rows_are_skipped_and_numbers_follow_the_sheet(): void
    {
        $rows = $this->read('libreoffice-mixed.ods');

        $this->assertSame([1, 2, 3, 4, 5, 8], array_keys($rows));
        $this->assertSame([null, null, 'lent'], $rows[8]);
    }

    public function test_repeated_columns_and_rows_expand(): void
    {
        $rows = $this->read('repeated.ods');

        $this->assertSame([
            1 => [1, 1, 1, null, null, 'x'],
            2 => ['r', 2],
            3 => ['r', 2],
            4 => ['r', 2],
            10 => [null, null, null, 'end'],
        ], $rows);
    }

    public function test_trailing_repeated_empty_cells_do_not_create_16k_columns(): void
    {
        $rows = $this->read('repeated.ods');

        $this->assertCount(6, $rows[1]);
        $this->assertSame(7, count($this->read('libreoffice-mixed.ods')[1]));
    }

    public function test_trailing_repeated_empty_rows_do_not_loop_million_times(): void
    {
        $start = microtime(true);
        $rows = $this->read('repeated.ods');
        $elapsed = microtime(true) - $start;

        $this->assertCount(5, $rows);
        $this->assertLessThan(1.0, $elapsed);
    }

    public function test_repeated_row_yields_consecutive_numbers_with_the_same_cells(): void
    {
        $rows = $this->readXml('<table:table-row table:number-rows-repeated="2"><table:table-cell office:value-type="float" office:value="1"/></table:table-row>'
            .'<table:table-row table:number-rows-repeated="3"/><table:table-row><table:table-cell office:value-type="string"><text:p>a</text:p></table:table-cell></table:table-row>');

        $this->assertSame([1 => [1], 2 => [1], 6 => ['a']], $rows);
    }

    public function test_hostile_column_repeat_is_refused_instead_of_exhausting_memory(): void
    {
        $this->expectException(LimitExceeded::class);

        $this->readXml('<table:table-row><table:table-cell office:value-type="float" office:value="1" table:number-columns-repeated="2000000000"/></table:table-row>');
    }

    public function test_sheet_selection_by_name_and_index_and_unknown_throws(): void
    {
        $reader = new OdsReader(Fixtures::committed('ods/multi-sheet.ods'), new ReadOptions);

        $this->assertSame([1 => ['első']], iterator_to_array($reader->rows()));
        $this->assertSame([1 => ['rejtett']], iterator_to_array($reader->rows(1)));
        $this->assertSame([1 => ['utolsó']], iterator_to_array($reader->rows('Utolsó')));

        try {
            $reader->rows('Nincs');
            $this->fail('An unknown sheet name must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Nincs', $exception->getMessage());
            $this->assertStringContainsString('"Első", "Rejtett", "Utolsó"', $exception->getMessage());
        }

        try {
            $reader->rows(3);
            $this->fail('An unknown sheet index must throw.');
        } catch (InvalidArgumentException $exception) {
            $this->assertStringContainsString('Első', $exception->getMessage());
        }

        $reader->close();
    }

    public function test_sheets_lists_names_in_order_with_visibility(): void
    {
        $reader = new OdsReader(Fixtures::committed('ods/multi-sheet.ods'), new ReadOptions);

        $this->assertEquals([
            new SheetInfo('Első', 0, true),
            new SheetInfo('Rejtett', 1, false),
            new SheetInfo('Utolsó', 2, true),
        ], $reader->sheets());

        $reader->close();
    }

    public function test_rows_with_header(): void
    {
        $reader = new OdsReader(Fixtures::committed('ods/libreoffice-mixed.ods'), new ReadOptions);
        $rows = iterator_to_array($reader->rowsWithHeader());
        $reader->close();

        $this->assertSame([2, 3, 4, 5, 8], array_keys($rows));
        $this->assertSame(42, $rows[2]['Egész']);
        $this->assertSame('lent', $rows[8]['Szöveg']);
    }

    public function test_rows_can_be_iterated_again_and_side_by_side(): void
    {
        $reader = new OdsReader(Fixtures::committed('ods/libreoffice-mixed.ods'), new ReadOptions);
        $first = $reader->rows();
        $second = $reader->rows();

        $this->assertSame($first->current(), $second->current());
        $first->next();
        $this->assertSame(42, $first->current()[0]);
        $this->assertSame('Egész', $second->current()[0]);
        $this->assertCount(6, iterator_to_array($reader->rows()));

        $reader->close();
    }

    public function test_close_releases_open_generators_and_resuming_throws(): void
    {
        $reader = new OdsReader(Fixtures::committed('ods/libreoffice-mixed.ods'), new ReadOptions);
        $rows = $reader->rows();
        $this->assertSame('Egész', $rows->current()[0]);

        $reader->close();
        $reader->close();

        // The reader stays usable: a new pass reopens the archive.
        $this->assertSame('Egész', $reader->rows()->current()[0]);
        $reader->close();

        $this->expectException(LogicException::class);

        $rows->next();
    }

    public function test_not_a_zip_is_corrupt(): void
    {
        $this->expectException(CorruptFile::class);

        new OdsReader(Fixtures::file('not a zip', 'ods'), new ReadOptions);
    }

    public function test_zip_without_content_is_corrupt(): void
    {
        $this->expectException(CorruptFile::class);

        new OdsReader(Fixtures::zip(['mimetype' => 'application/vnd.oasis.opendocument.spreadsheet'], 'ods'), new ReadOptions);
    }

    public function test_entry_limit_applies(): void
    {
        $this->expectException(LimitExceeded::class);

        new OdsReader(Fixtures::committed('ods/libreoffice-mixed.ods'), new ReadOptions(maxZipEntries: 2));
    }

    public function test_truncated_table_is_corrupt_after_the_rows_before_it(): void
    {
        $reader = new OdsReader(self::truncated(10), new ReadOptions);
        $seen = [];

        try {
            foreach ($reader->rows() as $number => $row) {
                $seen[$number] = $row;
            }

            $this->fail('A truncated table must throw.');
        } catch (CorruptFile) {
            // libxml may report the break before the rows parsed just ahead of it are handed out.
            $this->assertLessThanOrEqual(10, count($seen));
            $this->assertSame(array_slice(range(1, 10), 0, count($seen)), array_column($seen, 0));
        } finally {
            $reader->close();
        }
    }

    public function test_file_ending_inside_the_last_row_without_any_closing_tag_is_corrupt(): void
    {
        $path = Fixtures::zip(['content.xml' => '<office:document-content '.self::NS.'><office:body><office:spreadsheet><table:table table:name="A"><table:table-row>'], 'ods');

        $this->expectException(CorruptFile::class);

        $reader = new OdsReader($path, new ReadOptions);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_truncated_table_fails_when_generators_interleave(): void
    {
        $modeBefore = libxml_use_internal_errors();
        $good = new OdsReader(Fixtures::committed('ods/libreoffice-mixed.ods'), new ReadOptions);
        $bad = new OdsReader(self::truncated(2000), new ReadOptions);

        $first = $good->rows();
        $first->current();
        $second = $bad->rows();
        $second->current();
        $this->assertCount(6, iterator_to_array($first));

        $count = 0;

        try {
            foreach ($second as $row) {
                $count++;
            }

            $this->fail('A truncated table must throw.');
        } catch (CorruptFile) {
            $this->assertLessThanOrEqual(2000, $count);
        } finally {
            $good->close();
            $bad->close();
        }

        $this->assertSame($modeBefore, libxml_use_internal_errors());
    }

    public function test_valid_file_stays_valid_beside_a_corrupt_one(): void
    {
        $good = new OdsReader(Fixtures::committed('ods/libreoffice-mixed.ods'), new ReadOptions);
        $bad = new OdsReader(self::truncated(2000), new ReadOptions);
        $expected = array_map(self::normalize(...), $this->read('libreoffice-mixed.ods'));

        $first = $good->rows();
        $collected = [$first->key() => self::normalize($first->current())];

        try {
            iterator_to_array($bad->rows());
            $this->fail('A truncated table must throw.');
        } catch (CorruptFile) {
            // expected
        }

        $second = $bad->rows();
        $second->current();

        for ($first->next(); $first->valid(); $first->next()) {
            $collected[$first->key()] = self::normalize($first->current());
        }

        $this->assertSame($expected, $collected);

        try {
            iterator_to_array($second);
            $this->fail('A truncated table must throw.');
        } catch (CorruptFile) {
            // expected
        } finally {
            $good->close();
            $bad->close();
        }
    }

    public function test_doctype_with_internal_subset_is_refused(): void
    {
        $path = Fixtures::zip(['content.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e "boom">]>'
            .'<office:document-content '.self::NS.'><office:body><office:spreadsheet><table:table table:name="A"><table:table-row>'
            .'<table:table-cell office:value-type="string"><text:p>&e;</text:p></table:table-cell></table:table-row></table:table>'
            .'</office:spreadsheet></office:body></office:document-content>'], 'ods');

        $this->expectException(CorruptFile::class);

        $reader = new OdsReader($path, new ReadOptions);

        try {
            iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    public function test_date_values(): void
    {
        $rows = $this->readXml('<table:table-row>'
            .'<table:table-cell office:value-type="date" office:date-value="2025-01-31"/>'
            .'<table:table-cell office:value-type="date" office:date-value="2025-01-31T08:05:09"/>'
            .'<table:table-cell office:value-type="date" office:date-value="2025-01-31T23:59:58.25"/>'
            .'</table:table-row>');

        $this->assertSame([1 => ['date:2025-01-31 00:00:00', 'date:2025-01-31 08:05:09', 'date:2025-01-31 23:59:58']], array_map(self::normalize(...), $rows));
        $this->assertInstanceOf(DateTimeImmutable::class, $rows[1][2]);
        $this->assertSame('250000', $rows[1][2]->format('u'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidDates(): iterable
    {
        yield 'relative word' => ['tomorrow'];
        yield 'free text' => ['nonsense'];
        yield 'impossible day' => ['2025-02-30'];
        yield 'impossible hour' => ['2025-01-31T24:00:00'];
        yield 'space separator' => ['2025-01-31 13:45:00'];
    }

    #[DataProvider('invalidDates')]
    public function test_dates_are_parsed_strictly(string $value): void
    {
        $this->expectException(CorruptFile::class);

        $this->readXml('<table:table-row><table:table-cell office:value-type="date" office:date-value="'.$value.'"/></table:table-row>');
    }

    public function test_empty_string_and_unparseable_values_are_handled_like_xlsx(): void
    {
        $rows = $this->readXml('<table:table-row>'
            .'<table:table-cell office:value-type="string"><text:p/></table:table-cell>'
            .'<table:table-cell office:value-type="string" office:string-value=""/>'
            .'<table:table-cell office:value-type="float"/>'
            .'<table:table-cell office:value-type="boolean" office:boolean-value="false"/>'
            .'<table:table-cell office:value-type="float" office:value="12345678901234567890"/>'
            .'<table:table-cell office:value-type="float" office:value="-0.5"/>'
            .'</table:table-row>');

        $this->assertSame([1 => [null, null, null, false, 1.2345678901234567E19, -0.5]], $rows);
    }

    public function test_pretty_printed_content_ignores_layout_whitespace(): void
    {
        $rows = $this->readXml("\n <table:table-row>\n  <table:table-cell office:value-type=\"string\">\n   <text:p>a </text:p>\n   <text:p> b</text:p>\n  </table:table-cell>\n"
            ."  <table:table-cell office:value-type=\"float\" office:value=\"1\">\n   <text:p>1</text:p>\n  </table:table-cell>\n </table:table-row>\n");

        $this->assertSame([1 => ["a \n b", 1]], $rows);
    }

    public function test_row_groups_and_header_rows_are_transparent(): void
    {
        $rows = $this->readXml('<table:table-row-group><table:table-header-rows><table:table-row><table:table-cell office:value-type="float" office:value="1"/></table:table-row></table:table-header-rows>'
            .'<table:table-rows><table:table-row><table:table-cell office:value-type="float" office:value="2"/></table:table-row></table:table-rows></table:table-row-group>');

        $this->assertSame([1 => [1], 2 => [2]], $rows);
    }

    public function test_non_empty_repeat_beyond_the_row_cap_throws_limit_exceeded(): void
    {
        $this->expectException(LimitExceeded::class);

        $this->readXml('<table:table-row table:number-rows-repeated="999999999999"><table:table-cell office:value-type="float" office:value="1"/></table:table-row>');
    }

    public function test_non_empty_row_past_the_cap_throws_limit_exceeded(): void
    {
        $this->expectException(LimitExceeded::class);

        $this->readXml('<table:table-row table:number-rows-repeated="1048576"/><table:table-row><table:table-cell office:value-type="float" office:value="1"/></table:table-row>');
    }

    public function test_trailing_empty_repeat_beyond_the_cap_is_skipped(): void
    {
        $rows = $this->readXml('<table:table-row><table:table-cell office:value-type="float" office:value="1"/></table:table-row>'
            .'<table:table-row table:number-rows-repeated="1048575"><table:table-cell/></table:table-row>'
            .'<table:table-row table:number-rows-repeated="999999999999"/>');

        $this->assertSame([1 => [1]], $rows);
    }

    public function test_repeat_ending_exactly_on_the_cap_is_allowed(): void
    {
        $reader = new OdsReader(self::singleTable('<table:table-row table:number-rows-repeated="1048575"/><table:table-row><table:table-cell office:value-type="float" office:value="7"/></table:table-row>'), new ReadOptions);

        try {
            $this->assertSame([1_048_576 => [7]], iterator_to_array($reader->rows()));
        } finally {
            $reader->close();
        }
    }

    public function test_memory_flat(): void
    {
        $small = $this->buildLarge(30_000);
        $large = $this->buildLarge(300_000);
        $options = new ReadOptions;

        $smallGrowth = self::peakGrowth(fn () => $this->consume($small, $options, 30_000));
        $largeGrowth = self::peakGrowth(fn () => $this->consume($large, $options, 300_000));

        $this->assertLessThan(8 * 1024 * 1024, $largeGrowth - $smallGrowth);
    }

    private function consume(string $path, ReadOptions $options, int $expectedRows): void
    {
        $reader = new OdsReader($path, $options);
        $count = 0;
        $last = [];

        foreach ($reader->rows() as $row) {
            $count++;
            $last = $row;
        }

        $reader->close();

        $this->assertSame($expectedRows, $count);
        $this->assertSame($expectedRows, $last[0]);
        $this->assertSame("unique {$expectedRows} c", $last[7]);
        $this->assertSame('pool '.($expectedRows % 1000), $last[8]);
    }

    private function buildLarge(int $rows): string
    {
        $builder = new OdsFixtureBuilder(Fixtures::path('ods'));
        $date = new DateTimeImmutable('2025-01-31');

        for ($i = 1; $i <= $rows; $i++) {
            $pool = 'pool '.($i % 1000);
            $builder->addRow([$i, $i * 1.5, $date, "unique {$i} a", $pool, "unique {$i} b", $pool, "unique {$i} c", $pool, $i % 2 === 0]);
        }

        return $builder->close();
    }

    /**
     * @return array<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private function read(string $fixture): array
    {
        $reader = new OdsReader(Fixtures::committed('ods/'.$fixture), new ReadOptions);

        try {
            return iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    /**
     * @return array<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private function readXml(string $tableContent): array
    {
        $reader = new OdsReader(self::singleTable($tableContent), new ReadOptions);

        try {
            return iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  list<int|float|string|bool|DateTimeImmutable|null>  $row
     * @return list<int|float|string|bool|null>
     */
    private static function normalize(array $row): array
    {
        return array_map(
            static fn (int|float|string|bool|DateTimeImmutable|null $value): int|float|string|bool|null => $value instanceof DateTimeImmutable
                ? 'date:'.$value->format('Y-m-d H:i:s')
                : $value,
            $row,
        );
    }

    private static function singleTable(string $tableContent, bool $close = true): string
    {
        return Fixtures::zip([
            'mimetype' => 'application/vnd.oasis.opendocument.spreadsheet',
            'content.xml' => '<office:document-content '.self::NS.'><office:body><office:spreadsheet><table:table table:name="Sheet1">'
                .$tableContent.($close ? '</table:table></office:spreadsheet></office:body></office:document-content>' : ''),
        ], 'ods');
    }

    private static function truncated(int $rows): string
    {
        $data = '';

        for ($i = 1; $i <= $rows; $i++) {
            $data .= '<table:table-row><table:table-cell office:value-type="float" office:value="'.$i.'"/></table:table-row>';
        }

        return self::singleTable($data.'<table:table-row><table:table-cell office:value-type="float" office:value="2', false);
    }
}
