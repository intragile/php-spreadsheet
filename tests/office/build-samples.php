<?php

declare(strict_types=1);

/*
 * Writes the sample set that is opened in real office applications (Excel, Numbers, LibreOffice)
 * before a release, plus expected.json with the cell values each sample must show.
 *
 * Usage: php tests/office/build-samples.php <output-dir>
 */

use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Style;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$dir = rtrim($argv[1] ?? '', '/');

if ($dir === '' || (! is_dir($dir) && ! mkdir($dir, 0777, true))) {
    fwrite(STDERR, "Usage: php build-samples.php <output-dir>\n");
    exit(1);
}

$header = ['ID', 'Név', 'Összeg', 'Dátum', 'Aktív', 'Megjegyzés', 'Kód', 'Képlet-szöveg'];

/** Rows with null gaps in the middle and at the end, text that looks like numbers or formulas. */
$plainRows = [
    [1, 'Árvíztűrő tükörfúrógép', 1234.56, new DateTimeImmutable('2026-01-15'), true, 'első sor', '00123', '=1+1'],
    [2, 'Őrmester Ügyfél', 99.5, new DateTimeImmutable('2026-02-28 13:45:00'), false, null, '1e5', null],
    [3, null, -42, new DateTimeImmutable('1999-12-31'), true, "több\nsoros", null, '+36 1 234 5678'],
    [4, 'Győr', 0.1, null, null, null, null, null],
    [5, 'Szőlős', 1_000_000, new DateTimeImmutable('2026-11-10'), false, 'utolsó', '007', '@mention'],
];

$expected = [];

foreach (['xlsx', 'ods'] as $format) {
    // Plain: one sheet, no styles.
    $path = "{$dir}/plain.{$format}";
    $writer = Spreadsheet::write($path);
    $writer->sheet('Adatok')->addRow($header);

    foreach ($plainRows as $row) {
        $writer->addRow($row);
    }

    $writer->close();
    $expected["plain.{$format}"] = ['Adatok' => sampleExpectation($header, $plainRows)];

    // Styled: freeze pane, autofilter, column widths, number and date formats, a formula, two sheets.
    $path = "{$dir}/styled.{$format}";
    $writer = Spreadsheet::write($path);
    $bold = Style::make()->bold()->fill('DDEBF7')->border()->align('center');
    $money = Style::make()->numberFormat('#,##0 "Ft"');
    $percent = Style::make()->numberFormat('0.00%');
    $huDate = Style::make()->numberFormat('yyyy.mm.dd');
    $wrap = Style::make()->wrap()->italic()->fontColor('C00000');

    $writer->sheet('Rendelések', new SheetOptions(columnWidths: [1 => 8, 2 => 32, 3 => 16, 4 => 14, 5 => 12, 6 => 24], freezeRows: 1, autoFilter: true))
        ->addRow(['ID', 'Ügyfél', 'Összeg', 'Dátum', 'Arány', 'Megjegyzés'], $bold);

    $styledRows = [];

    for ($i = 1; $i <= 30; $i++) {
        $styledRows[] = [
            $i,
            "Ügyfél {$i} – Árvíztűrő",
            Cell::of($i * 12_345, $money),
            Cell::of(new DateTimeImmutable(sprintf('2026-%02d-%02d', ($i % 12) + 1, ($i % 27) + 1)), $huDate),
            Cell::of($i / 40, $percent),
            $i % 5 === 0 ? null : Cell::text("megjegyzés {$i}", $wrap),
        ];
    }

    foreach ($styledRows as $row) {
        $writer->addRow($row);
    }

    $writer->addRow(['', 'Összesen', Cell::formula('=SUM(C2:C31)', $money)], $bold);
    $writer->sheet('Összesítő')->addRow(['Sorok', 30])->addRow(['Kész', true]);
    $writer->close();
}

// CSV for Hungarian Excel: UTF-8 with BOM, semicolon, formulas escaped.
$writer = new CsvWriter("{$dir}/excel-hu.csv", delimiter: ';', bom: true, escapeFormulas: true);
$writer->addRow($header);

foreach ($plainRows as $row) {
    $writer->addRow($row);
}

$writer->close();

file_put_contents("{$dir}/expected.json", json_encode($expected, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

echo "Samples written to {$dir}\n";

/**
 * @param  list<string>  $header
 * @param  list<list<int|float|string|bool|DateTimeInterface|null>>  $rows
 * @return list<list<string|int|float|bool|null>>
 */
function sampleExpectation(array $header, array $rows): array
{
    $out = [$header];

    foreach ($rows as $row) {
        $out[] = array_map(static fn (int|float|string|bool|DateTimeInterface|null $v): int|float|string|bool|null => $v instanceof DateTimeInterface ? $v->format('Y-m-d H:i:s') : $v, $row);
    }

    return $out;
}
