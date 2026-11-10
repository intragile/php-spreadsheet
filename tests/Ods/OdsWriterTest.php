<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Ods;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Generator;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Ods\OdsReader;
use IntrAgile\Spreadsheet\Ods\OdsWriter;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Style;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use RuntimeException;
use ZipArchive;

final class OdsWriterTest extends TestCase
{
    private const NS = [
        'office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
        'table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
        'text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
        'fo' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
        'number' => 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0',
        'config' => 'urn:oasis:names:tc:opendocument:xmlns:config:1.0',
        'manifest' => 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0',
    ];

    public function test_roundtrip_types(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow([
            42, 3.14, 'szöveg', true, false, new DateTimeImmutable('2025-01-31'), new DateTimeImmutable('2025-01-31 13:45:00'), null, Cell::formula('=A1*2'),
        ])->close();

        $rows = self::readRows($path);

        $this->assertSame([1], array_keys($rows));
        // The formula has no cached value and the null is not stored, so the row ends at the dates.
        $this->assertCount(7, $rows[1]);
        $this->assertSame([42, 3.14, 'szöveg', true, false], array_slice($rows[1], 0, 5));
        $this->assertEquals(new DateTimeImmutable('2025-01-31'), $rows[1][5]);
        $this->assertEquals(new DateTimeImmutable('2025-01-31 13:45:00'), $rows[1][6]);

        $content = self::part($path, 'content.xml');
        $this->assertStringContainsString('office:value-type="float" office:value="42"><text:p>42</text:p></table:table-cell>', $content);
        $this->assertStringContainsString('office:value-type="float" office:value="3.14"><text:p>3.14</text:p></table:table-cell>', $content);
        $this->assertStringContainsString('office:value-type="boolean" office:boolean-value="true"><text:p>TRUE</text:p></table:table-cell>', $content);
        $this->assertStringContainsString('office:date-value="2025-01-31"', $content);
        $this->assertStringContainsString('office:date-value="2025-01-31T13:45:00"', $content);
        $this->assertStringContainsString('table:formula="of:=[.A1]*2"', $content);
    }

    public function test_strings_never_coerced(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow(['00123', '=1+1', '1e5', 'TRUE'])->addRow([Cell::text('=2+2')])->close();

        $rows = self::readRows($path);

        $this->assertSame(['00123', '=1+1', '1e5', 'TRUE'], $rows[1]);
        $this->assertSame(['=2+2'], $rows[2]);
        $this->assertStringNotContainsString('table:formula', self::part($path, 'content.xml'));
    }

    public function test_control_chars_stripped(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow(["a\x01b", "c\x00d\x1Fe", "bad \xC3 utf8", 'a < b & c > "d"', "x\u{FFFE}y"])->close();

        $this->assertSame(['ab', 'cde', 'bad  utf8', 'a < b & c > "d"', 'xy'], self::readRows($path)[1]);
        self::assertWellFormed(self::part($path, 'content.xml'));
    }

    public function test_whitespace_preserved(): void
    {
        $path = Fixtures::path('ods');
        $strings = ['  lead', 'trail  ', ' ', 'a  b   c', "tab\there", "multi\nline", "cr\r\nlf", "\n", "  x\n  y  "];
        (new OdsWriter($path))->addRow($strings)->close();

        $this->assertSame(['  lead', 'trail  ', ' ', 'a  b   c', "tab\there", "multi\nline", "cr\nlf", "\n", "  x\n  y  "], self::readRows($path)[1]);
        $content = self::part($path, 'content.xml');
        $this->assertStringContainsString('<text:tab/>', $content);
        $this->assertStringContainsString('<text:s text:c="2"/>lead', $content);
        $this->assertStringContainsString('<text:p>multi</text:p><text:p>line</text:p>', $content);
    }

    public function test_styles_bold_fill_numberformat(): void
    {
        $path = Fixtures::path('ods');
        $header = Style::make()->bold()->italic()->fontColor('C00000')->fill('DDEBF7')->border('808080')->align('center')->wrap();
        $writer = new OdsWriter($path);

        for ($i = 0; $i < 50; $i++) {
            // A fresh but equal instance each time must share the registered style.
            $writer->addRow(['a', 1], Style::make()->bold()->fill('DDEBF7'));
        }

        $writer->addRow([Cell::text('h', $header), Cell::of(1234.5, Style::make()->numberFormat('#,##0.00')), Cell::of(0.25, Style::make()->numberFormat('0.00%'))]);
        $writer->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $cell1 = self::first($content, '//table:table-row[1]/table:table-cell[1]');
        $name = $cell1->getAttributeNS(self::NS['table'], 'style-name');
        $this->assertNotSame('', $name);
        $this->assertSame(100, self::nodeCount($content, '//table:table-cell[@table:style-name="'.$name.'"]'));

        $style = self::first($content, '//office:automatic-styles/style:style[@style:name="'.$name.'"]');
        $this->assertSame('table-cell', $style->getAttributeNS(self::NS['style'], 'family'));
        $this->assertSame('#DDEBF7', self::first($content, 'style:table-cell-properties', $style)->getAttributeNS(self::NS['fo'], 'background-color'));
        $this->assertSame('bold', self::first($content, 'style:text-properties', $style)->getAttributeNS(self::NS['fo'], 'font-weight'));

        $hStyle = self::styleOf($content, '//table:table-row[51]/table:table-cell[1]');
        $props = self::first($content, 'style:table-cell-properties', $hStyle);
        $this->assertSame('0.74pt solid #808080', $props->getAttributeNS(self::NS['fo'], 'border'));
        $this->assertSame('wrap', $props->getAttributeNS(self::NS['fo'], 'wrap-option'));
        $text = self::first($content, 'style:text-properties', $hStyle);
        $this->assertSame('italic', $text->getAttributeNS(self::NS['fo'], 'font-style'));
        $this->assertSame('#C00000', $text->getAttributeNS(self::NS['fo'], 'color'));
        $this->assertSame('center', self::first($content, 'style:paragraph-properties', $hStyle)->getAttributeNS(self::NS['fo'], 'text-align'));

        $money = self::dataStyleOf($content, '//table:table-row[51]/table:table-cell[2]');
        $this->assertSame('number-style', $money->localName);
        $number = self::first($content, 'number:number', $money);
        $this->assertSame('2', $number->getAttributeNS(self::NS['number'], 'decimal-places'));
        $this->assertSame('true', $number->getAttributeNS(self::NS['number'], 'grouping'));

        $percent = self::dataStyleOf($content, '//table:table-row[51]/table:table-cell[3]');
        $this->assertSame('percentage-style', $percent->localName);
        $this->assertSame('2', self::first($content, 'number:number', $percent)->getAttributeNS(self::NS['number'], 'decimal-places'));
        $this->assertSame(['a', 1], self::readRows($path)[1]);
    }

    public function test_date_formats(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow([
            new DateTimeImmutable('2025-01-31'),
            new DateTimeImmutable('2025-01-31 13:14:15'),
            Cell::of(new DateTimeImmutable('2025-02-01'), Style::make()->numberFormat('yyyy.mm.dd')),
            Cell::of(new DateTimeImmutable('2025-02-02'), Style::make()->bold()),
            Cell::of(new DateTimeImmutable('2025-02-02 08:05:00'), Style::make()->numberFormat('hh:mm')),
        ])->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $kinds = [];

        foreach ([1, 2, 3, 4, 5] as $position) {
            $style = self::dataStyleOf($content, '//table:table-cell['.$position.']');
            $this->assertSame('date-style', $style->localName);
            $kinds[$position] = implode(',', array_map(
                fn (DOMElement $e): string => $e->localName.($e->getAttributeNS(self::NS['number'], 'style') === 'long' ? ':long' : '').($e->localName === 'text' ? '='.$e->textContent : ''),
                self::children($style),
            ));
        }

        $this->assertSame('year:long,text=-,month:long,text=-,day:long', $kinds[1]);
        $this->assertSame('year:long,text=-,month:long,text=-,day:long,text= ,hours:long,text=:,minutes:long,text=:,seconds:long', $kinds[2]);
        $this->assertSame('year:long,text=.,month:long,text=.,day:long', $kinds[3]);
        $this->assertSame('year:long,text=-,month:long,text=-,day:long', $kinds[4]);
        $this->assertSame('hours:long,text=:,minutes:long', $kinds[5]);

        $row = self::readRows($path)[1];
        $this->assertEquals(new DateTimeImmutable('2025-01-31 13:14:15'), $row[1]);
        $this->assertEquals(new DateTimeImmutable('2025-02-02 08:05:00'), $row[4]);
    }

    public function test_date_format_variants_and_fallback(): void
    {
        $path = Fixtures::path('ods');
        $date = new DateTimeImmutable('2025-02-02 15:04:05');
        (new OdsWriter($path))->addRow([
            Cell::of($date, Style::make()->numberFormat('d mmm yy h:mm AM/PM')),
            Cell::of($date, Style::make()->numberFormat('dddd, mmmm d')),
            Cell::of($date, Style::make()->numberFormat('hh:mm:ss.000')),
        ])->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $kinds = [];

        foreach ([1, 2, 3] as $position) {
            $kinds[$position] = implode(',', array_map(
                fn (DOMElement $e): string => $e->localName.($e->getAttributeNS(self::NS['number'], 'style') === 'long' ? ':long' : '').($e->getAttributeNS(self::NS['number'], 'textual') === 'true' ? ':textual' : ''),
                self::children(self::dataStyleOf($content, '//table:table-cell['.$position.']')),
            ));
        }

        $this->assertSame('day,text,month:textual,text,year,text,hours,text,minutes:long,text,am-pm', $kinds[1]);
        $this->assertSame('day-of-week:long,text,month:long:textual,text,day', $kinds[2]);
        // Fractional seconds have no equivalent, so the default date-time format applies.
        $this->assertSame('year:long,text,month:long,text,day:long,text,hours:long,text,minutes:long,text,seconds:long', $kinds[3]);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function numberFormats(): iterable
    {
        yield 'integer' => ['0', 'number-style', 'number:number{dp=0}'];
        yield 'grouped' => ['#,##0', 'number-style', 'number:number{dp=0,g}'];
        yield 'percent' => ['0%', 'percentage-style', 'number:number{dp=0},number:text=%'];
        yield 'suffix literal' => ['#,##0 "Ft"', 'number-style', 'number:number{dp=0,g},number:text= Ft'];
        yield 'prefix literal' => ['"$"0.00', 'number-style', 'number:text=$,number:number{dp=2}'];
        yield 'escaped literal' => ['0.0\\x', 'number-style', 'number:number{dp=1},number:text=x'];
        yield 'unsupported scientific' => ['0.00E+00', 'number-style', 'number:number{dp=-}'];
        yield 'unsupported fraction' => ['# ?/?', 'number-style', 'number:number{dp=-}'];
        yield 'general' => ['General', '', ''];
    }

    #[DataProvider('numberFormats')]
    public function test_number_format_mapping(string $code, string $element, string $children): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow([Cell::of(1234.5, Style::make()->numberFormat($code))])->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        if ($element === '') {
            $this->assertSame('', self::styleOf($content, '//table:table-cell')->getAttributeNS(self::NS['style'], 'data-style-name'));

            return;
        }

        $style = self::dataStyleOf($content, '//table:table-cell');
        $this->assertSame($element, $style->localName);
        $parts = array_map(function (DOMElement $e): string {
            if ($e->localName === 'text') {
                return 'number:text='.$e->textContent;
            }

            $attributes = [];
            $dp = $e->getAttributeNS(self::NS['number'], 'decimal-places');
            $attributes[] = 'dp='.($dp === '' ? '-' : $dp);

            if ($e->getAttributeNS(self::NS['number'], 'grouping') === 'true') {
                $attributes[] = 'g';
            }

            return 'number:number{'.implode(',', $attributes).'}';
        }, self::children($style));
        $this->assertSame($children, implode(',', $parts));
        $this->assertSame([1 => [1234.5]], self::readRows($path));
    }

    public function test_cell_style_replaces_row_style(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow(['row', Cell::text('cell', Style::make()->italic()), null], Style::make()->bold())->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $first = self::styleOf($content, '//table:table-cell[1]');
        $second = self::styleOf($content, '//table:table-cell[2]');
        $third = self::styleOf($content, '//table:table-cell[3]');

        $this->assertNotSame($first->getAttributeNS(self::NS['style'], 'name'), $second->getAttributeNS(self::NS['style'], 'name'));
        $this->assertSame($first->getAttributeNS(self::NS['style'], 'name'), $third->getAttributeNS(self::NS['style'], 'name'));
        $this->assertSame(0, self::nodeCount($content, 'style:text-properties[@fo:font-weight="bold"]', $second));
        $this->assertSame(1, self::nodeCount($content, 'style:text-properties[@fo:font-style="italic"]', $second));
    }

    public function test_gaps_are_repeated_cells(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))
            ->addRow([1, null, null, 4, '', null])
            ->addRow([])
            ->addRow([null, 'x'])
            ->close();

        $content = self::part($path, 'content.xml');

        $this->assertStringContainsString('<table:table-cell table:number-columns-repeated="2"/>', $content);
        // An empty row still holds one cell, as the schema requires.
        $this->assertStringContainsString('<table:table-row><table:table-cell/></table:table-row>', $content);
        $this->assertSame([1 => [1, null, null, 4], 3 => [null, 'x']], self::readRows($path));
    }

    public function test_too_many_columns_throws_and_aborts(): void
    {
        $writer = new OdsWriter(Fixtures::path('ods'));

        try {
            $writer->addRow(array_fill(0, 16385, 1));
            $this->fail('Expected exception');
        } catch (SpreadsheetException $e) {
            $this->assertStringContainsString('16384 columns', $e->getMessage());
        }

        $this->expectException(LogicException::class);
        $writer->addRow([1]);
    }

    public function test_column_widths_and_freeze(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))
            ->sheet('Data', new SheetOptions(columnWidths: [2 => 30, 4 => 12.5], freezeRows: 2))
            ->addRows([['a', 'b', 'c', 'd', 'e'], [1, 2, 3, 4, 5]])
            ->sheet('Plain')->addRow(['x'])
            ->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $columns = $content->query('//table:table[@table:name="Data"]/table:table-column');
        $this->assertNotFalse($columns);
        $layout = [];

        foreach ($columns as $column) {
            $this->assertInstanceOf(DOMElement::class, $column);
            $name = $column->getAttributeNS(self::NS['table'], 'style-name');
            $repeat = $column->getAttributeNS(self::NS['table'], 'number-columns-repeated');
            $width = $name === '' ? '-' : self::first($content, '//style:style[@style:name="'.$name.'"]/style:table-column-properties')->getAttributeNS(self::NS['style'], 'column-width');
            $layout[] = ($repeat === '' ? '1' : $repeat).'x'.$width;
        }

        // Columns 1, 3 and 5 keep the default width; 2 and 4 are set (30 and 12.5 characters).
        $this->assertSame(['1x-', '1x5.689cm', '1x-', '1x2.447cm', '1x-'], $layout);

        $settings = self::xpath(self::part($path, 'settings.xml'));
        $entry = self::first($settings, '//config:config-item-map-named[@config:name="Tables"]/config:config-item-map-entry[@config:name="Data"]');
        $items = [];

        foreach (self::children($entry) as $item) {
            $items[$item->getAttributeNS(self::NS['config'], 'name')] = $item->textContent;
        }

        $this->assertSame('2', $items['VerticalSplitMode']);
        $this->assertSame('2', $items['VerticalSplitPosition']);
        $this->assertSame('0', $items['HorizontalSplitMode']);
        $this->assertSame('2', $items['PositionBottom']);
        $this->assertSame(0, self::nodeCount($settings, '//config:config-item-map-entry[@config:name="Plain"]'));
        $this->assertSame('Data', self::first($settings, '//config:config-item[@config:name="ActiveTable"]')->textContent);
    }

    public function test_autofilter_is_a_database_range(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))
            ->sheet('R&D', new SheetOptions(autoFilter: true))
            ->addRows([['a', 'b', 'c'], [1, 2, 3], [4, 5, 6]])
            ->sheet('Empty', new SheetOptions(autoFilter: true))
            ->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $ranges = $content->query('//table:database-ranges/table:database-range');
        $this->assertNotFalse($ranges);
        $this->assertSame(1, $ranges->length);
        $range = $ranges->item(0);
        $this->assertInstanceOf(DOMElement::class, $range);
        $this->assertSame("'R&D'.A1:'R&D'.C3", $range->getAttributeNS(self::NS['table'], 'target-range-address'));
        $this->assertSame('true', $range->getAttributeNS(self::NS['table'], 'display-filter-buttons'));
    }

    public function test_multiple_sheets(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))
            ->sheet('Első')->addRow(['one'])
            ->sheet('R&D <2>')->addRow(['two'])
            ->sheet('Third')->addRow(['three'])
            ->close();

        $reader = new OdsReader($path, new ReadOptions);

        try {
            $this->assertSame(['Első', 'R&D <2>', 'Third'], array_map(fn (SheetInfo $s): string => $s->name, $reader->sheets()));
            $this->assertSame([1 => ['one']], iterator_to_array($reader->rows('Első')));
            $this->assertSame([1 => ['two']], iterator_to_array($reader->rows('R&D <2>')));
            $this->assertSame([1 => ['three']], iterator_to_array($reader->rows(2)));
        } finally {
            $reader->close();
        }
    }

    public function test_implicit_first_sheet_is_named_sheet1(): void
    {
        $path = Fixtures::path('ods');
        $writer = new OdsWriter($path);
        $writer->addRow(['a']);

        try {
            $writer->sheet('SHEET1');
            $this->fail('Expected exception');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('SHEET1', $e->getMessage());
        }

        $writer->sheet('Other')->addRow(['b'])->close();

        $reader = new OdsReader($path, new ReadOptions);
        $this->assertSame(['Sheet1', 'Other'], array_map(fn (SheetInfo $s): string => $s->name, $reader->sheets()));
        $reader->close();
    }

    /**
     * @return iterable<string, array{list<string>, string}>
     */
    public static function invalidSheetNames(): iterable
    {
        yield 'too long' => [[str_repeat('x', 32)], str_repeat('x', 32)];
        yield 'slash' => [['a/b'], 'a/b'];
        yield 'backslash' => [['a\\b'], 'a\\b'];
        yield 'bracket' => [['[x]'], '[x]'];
        yield 'colon' => [['a:b'], 'a:b'];
        yield 'star' => [['a*'], 'a*'];
        yield 'question' => [['a?'], 'a?'];
        yield 'empty' => [[''], '""'];
        yield 'apostrophe edge' => [["'quoted'"], "'quoted'"];
        yield 'control char' => [["a\x01b"], 'a'];
        yield 'xml-illegal U+FFFE' => [["a\u{FFFE}b"], 'cannot hold in XML'];
        yield 'duplicate ignoring case' => [['Sheet1', 'sheet1'], 'sheet1'];
        yield 'duplicate ignoring accent case' => [['Árvíz', 'árvíz'], 'árvíz'];
    }

    /**
     * @param  list<string>  $names
     */
    #[DataProvider('invalidSheetNames')]
    public function test_invalid_sheet_names_throw(array $names, string $expected): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new OdsWriter($dir.'/out.ods');
            $last = array_pop($names);
            $this->assertNotNull($last);

            foreach ($names as $name) {
                $writer->sheet($name);
            }

            try {
                $writer->sheet($last);
                $this->fail('Expected exception');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($expected, $e->getMessage());
            }

            if ($names === []) {
                $this->assertSame([], glob($dir.'/*'));
            }

            $writer->sheet('Valid')->addRow(['ok'])->close();
            $this->assertFileExists($dir.'/out.ods');
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_mimetype_is_first_and_stored_uncompressed(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->sheet('A')->addRow(['x'])->sheet('B')->addRow(['y'])->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::RDONLY));

        try {
            $stat = $zip->statIndex(0);
            $this->assertIsArray($stat);
            $this->assertSame('mimetype', $stat['name']);
            $this->assertSame(0, $stat['comp_method']);
            $this->assertSame('application/vnd.oasis.opendocument.spreadsheet', $zip->getFromIndex(0));
        } finally {
            $zip->close();
        }
    }

    public function test_package_is_well_formed_and_manifest_lists_every_part(): void
    {
        $path = Fixtures::path('ods');
        $header = Style::make()->bold()->fill('DDEBF7')->border('808080')->align('center');
        (new OdsWriter($path))
            ->sheet('Summary', new SheetOptions([1 => 20, 3 => 12.5], 1, true))
            ->addRow(['Name', 'Amount', 'Date'], $header)
            ->addRow(['Alice', Cell::of(1234.5, Style::make()->numberFormat('#,##0.00')), new DateTimeImmutable('2025-03-01 08:30:00')])
            ->addRow(['Bob', Cell::formula('=B2*2'), null])
            ->sheet('Details')
            ->addRow([Cell::text('wrapped', Style::make()->wrap()->italic()->fontColor('C00000')), true, -7])
            ->close();

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path, ZipArchive::RDONLY));
        $names = [];

        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $names[] = $name;

                if ($name !== 'mimetype') {
                    self::assertWellFormed((string) $zip->getFromIndex($i), $name);
                }
            }

            $manifest = self::xpath((string) $zip->getFromName('META-INF/manifest.xml'));
        } finally {
            $zip->close();
        }

        $this->assertSame(['mimetype', 'META-INF/manifest.xml', 'meta.xml', 'settings.xml', 'styles.xml', 'content.xml'], $names);
        $entries = $manifest->query('//manifest:file-entry');
        $this->assertNotFalse($entries);
        $listed = [];

        foreach ($entries as $entry) {
            $this->assertInstanceOf(DOMElement::class, $entry);
            $listed[] = $entry->getAttributeNS(self::NS['manifest'], 'full-path');
        }

        $this->assertSame(['/', 'content.xml', 'styles.xml', 'meta.xml', 'settings.xml'], $listed);
        $this->assertSame(
            'application/vnd.oasis.opendocument.spreadsheet',
            self::first($manifest, '//manifest:file-entry[@manifest:full-path="/"]')->getAttributeNS(self::NS['manifest'], 'media-type'),
        );
        $this->assertSame(['Name', 'Amount', 'Date'], self::readRows($path)[1]);
    }

    public function test_close_without_rows_creates_a_valid_empty_workbook(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->close();

        $reader = new OdsReader($path, new ReadOptions);
        $this->assertSame('Sheet1', $reader->sheets()[0]->name);
        $this->assertSame([], iterator_to_array($reader->rows()));
        $reader->close();
    }

    public function test_numbers_round_trip_exactly(): void
    {
        $path = Fixtures::path('ods');
        $values = [0, -1, PHP_INT_MAX >> 12, 0.1, 0.30000000000000004, -2.5e-10, 1.5e20];
        (new OdsWriter($path))->addRow($values)->close();

        $this->assertSame($values, self::readRows($path)[1]);
    }

    public function test_float_mapping_ignores_locale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        setlocale(LC_NUMERIC, 'hu_HU.UTF-8', 'hu_HU', 'de_DE.UTF-8');

        try {
            $path = Fixtures::path('ods');
            (new OdsWriter($path))->addRow([1234.5])->close();
        } finally {
            setlocale(LC_NUMERIC, $previous === false ? 'C' : $previous);
        }

        $this->assertStringContainsString('office:value="1234.5"', self::part($path, 'content.xml'));
    }

    public function test_non_finite_float_throws_and_aborts(): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new OdsWriter($dir.'/out.ods');
            $writer->addRow(['ok']);

            try {
                $writer->addRow([NAN]);
                $this->fail('Expected exception');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('finite', $e->getMessage());
            }

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_infinite_float_throws(): void
    {
        $writer = new OdsWriter(Fixtures::path('ods'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('finite');

        $writer->addRow([-INF]);
    }

    public function test_atomic_close_and_idempotence(): void
    {
        $path = Fixtures::path('ods');
        $writer = new OdsWriter($path);
        $writer->addRow(['a', 'b']);

        $this->assertFileDoesNotExist($path);

        $writer->close();
        $this->assertFileExists($path);
        $size = filesize($path);

        $writer->close();
        $this->assertSame($size, filesize($path));
        $this->assertSame([1 => ['a', 'b']], self::readRows($path));
    }

    public function test_exception_mid_write_leaves_no_files(): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new OdsWriter($dir.'/out.ods');
            $writer->sheet('First')->addRow(['done']);
            $rows = (function (): Generator {
                yield ['ok'];

                throw new RuntimeException('boom');
            })();

            try {
                $writer->sheet('Second')->addRows($rows);
                $this->fail('Expected exception');
            } catch (RuntimeException $e) {
                $this->assertSame('boom', $e->getMessage());
            }

            try {
                $writer->close();
                $this->fail('Expected exception');
            } catch (LogicException $e) {
                $this->assertStringContainsString('aborted', $e->getMessage());
            }

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_unsupported_value_throws(): void
    {
        $writer = new OdsWriter(Fixtures::path('ods'));

        $this->expectException(InvalidArgumentException::class);

        $writer->addRow([new \stdClass]); // @phpstan-ignore argument.type
    }

    public function test_write_after_close_throws(): void
    {
        $writer = new OdsWriter(Fixtures::path('ods'));
        $writer->close();

        $this->expectException(LogicException::class);

        $writer->addRow(['a']);
    }

    public function test_sheet_after_close_throws(): void
    {
        $writer = new OdsWriter(Fixtures::path('ods'));
        $writer->close();

        $this->expectException(LogicException::class);

        $writer->sheet('Late');
    }

    public function test_destruct_without_close_removes_temps_and_never_creates_target(): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new OdsWriter($dir.'/out.ods');
            $writer->sheet('One')->addRow(['a'])->sheet('Two')->addRow(['b']);
            $this->assertCount(2, glob($dir.'/*') ?: []);

            unset($writer);

            $this->assertSame([], glob($dir.'/*'));
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_only_one_sheet_handle_is_open_at_a_time(): void
    {
        $writer = new OdsWriter(Fixtures::path('ods'));
        $writer->sheet('One')->addRow(['a'])->sheet('Two')->addRow(['b']);

        $handles = array_filter(get_resources('stream'), fn ($h): bool => str_contains(stream_get_meta_data($h)['uri'] ?? '', 'sps_ods_'));
        $this->assertCount(1, $handles);

        $writer->close();
    }

    /**
     * @return iterable<string, array{callable(string): mixed}>
     */
    public static function brokenSheetParts(): iterable
    {
        yield 'unreadable when the content is assembled' => [static fn (string $path): bool => chmod($path, 0)];
        yield 'missing when the content is assembled' => [static fn (string $path): bool => unlink($path)];
    }

    /**
     * @param  callable(string): mixed  $break
     */
    #[DataProvider('brokenSheetParts')]
    public function test_assembly_failure_throws_and_cleans_up(callable $break): void
    {
        $dir = self::emptyDirectory();

        try {
            $writer = new OdsWriter($dir.'/out.ods');
            $writer->sheet('One')->addRow(['a'])->sheet('Two')->addRow(['b']);

            $finished = new ReflectionProperty(OdsWriter::class, 'sheetPaths');
            /** @var list<string> $paths */
            $paths = $finished->getValue($writer);
            $break($paths[0]);

            try {
                $writer->close();
                $this->fail('Expected exception');
            } catch (SpreadsheetException $e) {
                $this->assertStringContainsString('out.ods', $e->getMessage());
            }

            $this->assertSame([], glob($dir.'/*'));
            try {
                $writer->close();
                $this->fail('Expected exception');
            } catch (LogicException $e) {
                $this->assertStringContainsString('aborted', $e->getMessage());
            }
        } finally {
            self::removeDirectory($dir);
        }
    }

    public function test_missing_target_directory_throws_naming_it(): void
    {
        $writer = new OdsWriter('/nonexistent-sps-dir/out.ods');

        $this->expectException(SpreadsheetException::class);
        $this->expectExceptionMessage('/nonexistent-sps-dir');

        $writer->addRow(['a']);
    }

    public function test_final_file_has_umask_permissions(): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow(['a'])->close();

        clearstatcache();
        $this->assertSame(0666 & ~umask(), fileperms($path) & 0777);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function formulas(): iterable
    {
        yield 'cell reference' => ['=A1*2', 'of:=[.A1]*2'];
        yield 'range and absolute' => ['=SUM($A$1:B10)', 'of:=SUM([.$A$1:.B10])'];
        yield 'argument separator' => ['=IF(A1>0,"a,b","c")', 'of:=IF([.A1]>0;"a,b";"c")'];
        yield 'function names untouched' => ['=LOG10(100)+ATAN2(1,2)', 'of:=LOG10(100)+ATAN2(1;2)'];
        yield 'sheet reference left as is' => ['=Sheet2!A1', 'of:=Sheet2!A1'];
        yield 'escaped' => ['=A1&"<x>"', 'of:=[.A1]&"<x>"'];
    }

    #[DataProvider('formulas')]
    public function test_formula_attribute(string $formula, string $expected): void
    {
        $path = Fixtures::path('ods');
        (new OdsWriter($path))->addRow([Cell::formula($formula)])->close();

        $content = self::xpath(self::part($path, 'content.xml'));
        $this->assertSame($expected, self::first($content, '//table:table-cell')->getAttributeNS(self::NS['table'], 'formula'));
    }

    public function test_memory_flat(): void
    {
        $small = $this->writeRows(30_000);
        $large = $this->writeRows(300_000);

        $this->assertLessThan(8 * 1024 * 1024, $large - $small);
    }

    public function test_close_after_abort_throws_and_writes_nothing(): void
    {
        $path = Fixtures::path('ods');
        $writer = new OdsWriter($path);

        try {
            $writer->addRow([new \stdClass]);
            $this->fail('Expected exception');
        } catch (InvalidArgumentException) {
        }

        try {
            $writer->close();
            $this->fail('Expected exception');
        } catch (LogicException $e) {
            $this->assertStringContainsString('aborted', $e->getMessage());
            $this->assertStringContainsString('no file was written', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function test_float_text_is_shortest_round_trip_regardless_of_ini_precision(): void
    {
        $path = Fixtures::path('ods');
        $serialize = ini_get('serialize_precision');
        $precision = ini_get('precision');
        ini_set('serialize_precision', '5');
        ini_set('precision', '3');

        try {
            (new OdsWriter($path))->addRow([-0.0, 1.0E+25, 1e-7, 0.1 + 0.2, PHP_FLOAT_MAX, 5e-324, 1e16, 2.5])->close();
        } finally {
            ini_set('serialize_precision', (string) $serialize);
            ini_set('precision', (string) $precision);
        }

        preg_match_all('#office:value="([^"]*)"#', self::part($path, 'content.xml'), $m);
        $this->assertSame(['0', '1.0E+25', '1.0E-7', '0.30000000000000004', '1.7976931348623157E+308', '5.0E-324', '10000000000000000', '2.5'], $m[1]);
    }

    private function writeRows(int $count): int
    {
        $path = Fixtures::path('ods');
        $style = Style::make()->bold()->fill('DDEBF7');
        $date = new DateTimeImmutable('2025-01-31 10:00:00');

        $growth = self::peakGrowth(function () use ($path, $count, $style, $date): void {
            $writer = new OdsWriter($path);

            for ($i = 0; $i < $count; $i++) {
                $writer->addRow([$i, 'name '.$i, $i * 1.5, true, $date, 'x', null, -$i, 'árvíztűrő', 0.5], $style);
            }

            $writer->close();
        });

        $this->assertFileExists($path);

        return $growth;
    }

    /**
     * @return array<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private static function readRows(string $path): array
    {
        $reader = new OdsReader($path, new ReadOptions);

        try {
            return iterator_to_array($reader->rows());
        } finally {
            $reader->close();
        }
    }

    private static function part(string $path, string $name): string
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Cannot open '.$path);
        }

        try {
            $contents = $zip->getFromName($name);
        } finally {
            $zip->close();
        }

        if ($contents === false) {
            throw new RuntimeException('Missing part '.$name);
        }

        return $contents;
    }

    private static function nodeCount(DOMXPath $xpath, string $query, ?DOMElement $context = null): int
    {
        $nodes = $xpath->query($query, $context);
        self::assertNotFalse($nodes, $query);

        return $nodes->length;
    }

    private static function xpath(string $xml): DOMXPath
    {
        $document = new DOMDocument;
        self::assertTrue($document->loadXML($xml));
        $xpath = new DOMXPath($document);

        foreach (self::NS as $prefix => $uri) {
            $xpath->registerNamespace($prefix, $uri);
        }

        return $xpath;
    }

    private static function first(DOMXPath $xpath, string $query, ?DOMElement $context = null): DOMElement
    {
        $nodes = $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);
        self::assertInstanceOf(DOMElement::class, $node, $query);

        return $node;
    }

    /**
     * @return list<DOMElement>
     */
    private static function children(DOMElement $element): array
    {
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $children[] = $child;
            }
        }

        return $children;
    }

    /** The automatic cell style of the first cell matching the query. */
    private static function styleOf(DOMXPath $content, string $cellQuery): DOMElement
    {
        $name = self::first($content, $cellQuery)->getAttributeNS(self::NS['table'], 'style-name');

        return self::first($content, '//office:automatic-styles/style:style[@style:name="'.$name.'"]');
    }

    /** The data style of the first cell matching the query. */
    private static function dataStyleOf(DOMXPath $content, string $cellQuery): DOMElement
    {
        $name = self::styleOf($content, $cellQuery)->getAttributeNS(self::NS['style'], 'data-style-name');
        self::assertNotSame('', $name, 'cell has no data style');

        return self::first($content, '//office:automatic-styles/*[@style:name="'.$name.'"][not(self::style:style)]');
    }

    private static function assertWellFormed(string $xml, string $name = 'part'): void
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $document = new DOMDocument;
            $ok = $document->loadXML($xml);
            $errors = libxml_get_errors();
            libxml_clear_errors();
        } finally {
            libxml_use_internal_errors($previous);
        }

        self::assertTrue($ok && $errors === [], $name.' is not well-formed XML');
    }

    private static function emptyDirectory(): string
    {
        $dir = sys_get_temp_dir().'/sps_ow_'.bin2hex(random_bytes(4));
        mkdir($dir);

        return $dir;
    }

    private static function removeDirectory(string $dir): void
    {
        array_map('unlink', glob($dir.'/*') ?: []);
        rmdir($dir);
    }
}
