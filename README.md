# PHP Spreadsheet

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

**Read and write XLSX, ODS and CSV files of any size with constant memory and no Composer
dependencies.** Rows are streamed in and out one at a time, so a million-row export costs the same
RAM as a hundred-row one.

[Magyar nyelvű leírás → README.hu.md](README.hu.md)

```php
use IntrAgile\Spreadsheet\Spreadsheet;

$reader = Spreadsheet::read('/path/to/upload.xlsx');   // the format is detected from the content

foreach ($reader->rows() as $number => $row) {
    // $row is a list of int|float|string|bool|DateTimeImmutable|null
}

$reader->close();
```

## Installation

```bash
composer require intragile/php-spreadsheet
```

Requires PHP 8.3+ with `ext-zip`, `ext-xmlreader` and `ext-mbstring`. There are no Composer
dependencies.

## Reading

```php
$reader = Spreadsheet::read('/path/to/file.ods');

$reader->sheets();                       // list<SheetInfo>: name, index, visibility
$reader->rows();                         // first sheet; rows($nameOrIndex) picks another

foreach ($reader->rowsWithHeader() as $number => $record) {
    echo $record['email'];               // keyed by the trimmed header row
}

$reader->close();
```

- Keys of `rows()` are the real 1-based row numbers; fully empty rows are skipped.
- Cell types are preserved: numbers, booleans, text and dates (`DateTimeImmutable`, including
  dates behind custom number formats and the 1904 date system). CSV values are always strings.
- Duplicate or blank header cells become `name_2` / `column_N`.
- CSV delimiter and encoding are sniffed (including the Windows-1250, `;`-separated export of
  Hungarian Excel) and can be overridden:

```php
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\ReadOptions;

$reader = Spreadsheet::read(
    $path,
    new ReadOptions(csvDelimiter: ';', csvEncoding: 'Windows-1250'),
    Format::Csv,
);
```

A missing or unreadable file throws `SpreadsheetException`; `.xls`, Numbers and PDF files throw
`UnsupportedFormat`.

## Writing

The format follows the file extension (`.xlsx`, `.ods`, `.csv`), or pass a `Format`.

```php
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Style;

$writer = Spreadsheet::write('/path/to/report.xlsx');

$header = Style::make()->bold()->fill('DDDDDD')->border();

$writer
    ->sheet('Orders', new SheetOptions(columnWidths: [1 => 12, 2 => 30], freezeRows: 1, autoFilter: true))
    ->addRow(['ID', 'Customer', 'Total', 'Ordered'], $header);

foreach ($orders as $order) {                       // any iterable, including generators
    $writer->addRow([
        $order->id,                                 // number
        $order->customer,                           // always stored as text
        Cell::of($order->total, Style::make()->numberFormat('#,##0.00')),
        $order->orderedAt,                          // DateTimeInterface -> date
    ]);
}

$writer->addRow(['', 'Sum', Cell::formula('=SUM(C2:C100)')]);
$writer->close();                                   // the file appears atomically on close
```

- Strings are never coerced: `"00123"`, `"1e5"` and `"=1+1"` stay text. Use `Cell::formula()` for
  formulas and `Cell::text()` to force text.
- Characters that are illegal in XML are stripped instead of producing a corrupt file.
- Sheet names are validated (length, forbidden characters, case-insensitive duplicates) before
  anything is written.
- `Style` is immutable; equal styles share one entry in the file.

## Memory

No reader or writer holds more than the current row in memory, plus bounded caches. XLSX shared
strings are kept in RAM up to `ReadOptions::$sharedStringsInMemory` entries and spill to a
temporary file beyond that. Writers stream to a temporary file and rename it into place on
`close()`. The test suite asserts that peak memory does not grow with the row count.

## Safety limits

Uploaded files are untrusted, so readers enforce limits (all configurable in `ReadOptions`):

| Option | Default | Protects against |
|---|---|---|
| `maxZipEntries` | 5,000 | archives with huge entry counts |
| `maxUncompressedBytes` | 4 GiB | zip bombs, counted on the bytes actually decompressed |
| `maxCompressionRatio` | 200 | entries larger than 1 MiB that expand more than 200:1 |

Rows beyond 1,048,576 (the Excel and LibreOffice maximum) raise `LimitExceeded`, DOCTYPE
declarations in XML are refused (no XXE), and unsafe zip entry names are rejected. Limit
violations throw `LimitExceeded`; damaged files throw `CorruptFile`; both extend
`SpreadsheetException`.

## Benchmarks

See [bench/RESULTS.md](bench/RESULTS.md).

## Development

```bash
composer install
vendor/bin/phpunit
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## License

MIT. See [LICENSE](LICENSE).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
