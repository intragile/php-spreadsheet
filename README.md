# PHP Spreadsheet

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

**Read and write XLSX, ODS and CSV files with no Composer dependencies.** Rows are streamed in
and out one at a time, so memory stays flat as the row count grows (see [Memory](#memory) and
[bench/RESULTS.md](bench/RESULTS.md)).

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

Requires PHP 8.3+ with `ext-zip`, `ext-xmlreader`, `ext-mbstring` and `ext-iconv`. There are no
Composer dependencies.

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
- Duplicate or blank header cells become `name_2` / `column_N`. A header that is a decimal integer
  (such as `2024`) becomes an `int` array key, as PHP always stores such keys: `$record[2024]` and
  `$record['2024']` both work, but `array_keys()` returns `2024` as an `int`.
- A date-formatted XLSX cell whose serial lies outside the spreadsheet calendar (below 0 or after
  9999-12-31) is returned as the plain number instead of a date.
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

- CSV input in another encoding is decoded to UTF-8 losslessly: UTF-16 and UTF-32 and single-byte
  code pages such as Windows-1250 or ISO-8859-2. A byte the code page does not define (such as
  `0x81` in Windows-1250) becomes `U+FFFD` instead of failing the read. Other multi-byte encodings
  (Shift_JIS, GBK, Big5, EUC-KR) throw `InvalidArgumentException`; convert such files to UTF-8 first.
- UTF-16 and UTF-32 are detected with or without a byte-order mark (without one, from the position
  of the zero bytes, which works for text that is mostly Latin script). A `csvEncoding` without a
  byte order (`UTF-16`, `UCS-2`, `UTF-32`, `UCS-4`) takes it from the mark or, without one, from the
  detected order, and falls back to big-endian; `UTF-16LE` and the like are used as given.
- CSV exported by Excel for Mac is read as well. Its Mac Central European code page is detected
  when the text has enough Hungarian `á` and `é` to tell it from Windows-1250, which stays the
  default; for other text name it with `csvEncoding: 'MacCentralEurope'`. The "CSV Mac" format, whose
  lines end with a bare carriage return, is split into records (detected when, outside quoted fields,
  there are bare carriage returns and no line feed); a carriage return inside a quoted field of such
  a file becomes `\n`.
- `maxCellBytes` counts raw CSV bytes including enclosures. A small limit costs no speed on ordinary
  rows; rows about as long as the limit or longer are checked byte by byte and read about 2x slower.

A missing or unreadable file throws `SpreadsheetException` for every format, also when a reader
class is used directly. An empty (0-byte) file throws `UnsupportedFormat`; pass `Format::Csv` to read
it as an empty CSV. A file that starts like a zip archive but cannot be opened (a truncated upload)
throws `CorruptFile`. `.xlsb`, password-protected Office files, other OLE2 documents, Numbers and PDF files throw
`UnsupportedFormat` with a message naming them. A short binary file without NUL bytes cannot be told
apart from text and is read as CSV.

### Reading legacy XLS

Binary `.xls` workbooks are detected from their content (`Format::Xls`) and read through the optional
[phpoffice/phpspreadsheet](https://github.com/PHPOffice/PhpSpreadsheet) package (`composer require phpoffice/phpspreadsheet`;
without it, reading an XLS file throws `UnsupportedFormat`). Rows, sheet selection, `rowsWithHeader()` and
the value types work as for the other formats, including the 1904 date system. Unlike them, **XLS reading is
not streaming**: the whole workbook is loaded into memory. The sheet dimensions are read first, and a
workbook whose sheets hold more cells than `maxCells` is rejected with `LimitExceeded` before anything is
loaded. XLS files cannot be written.

### Inspecting a file

`FileInspector::inspect($path)` scans the first 20 non-empty rows of every sheet (adjustable with
`scanRows`) and reports the format, each sheet's guessed header row (a real 1-based row number, or `null`),
column count and sample rows, and `firstDataSheet`, the first visible sheet with data. The header is the
first scanned row with at least two non-numeric text cells that is followed by a non-empty row.
`FileInspector::countCells($path, stopAbove: 500_000)` counts non-empty cells and stops as soon as the limit
is passed (`exceeded`), so an oversized sheet is rejected without reading all of it.

```php
$inspection = FileInspector::inspect('contacts.xlsx');
$sheet = $inspection->sheets[$inspection->firstDataSheet ?? 0];
$header = $sheet->headerRow; // e.g. 3
```

## Writing

The format follows the file extension (`.xlsx`, `.ods`, `.csv`/`.txt`; `.txt` is written as CSV), or pass a `Format`.
`.xlsm` can be read but not written, as the written workbook would hold no macros.

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

- Strings are never coerced: `"00123"` and `"1e5"` stay text in every format, and `"=1+1"` stays
  text in XLSX and ODS. Use `Cell::formula()` for formulas and `Cell::text()` to force text. CSV has
  no cell types, so a spreadsheet application opening a CSV file runs `=1+1` as a formula; see
  [CSV for Excel](#csv-for-excel) for formula escaping.
- ODS formulas are converted to OpenFormula for plain A1 references, ranges and the `,` argument
  separator. References to other sheets (`Sheet2!A1`), quoted names and structured references are
  written unconverted, so such a formula may not evaluate in LibreOffice; use XLSX for them.
- Characters that are illegal in XML are stripped instead of producing a corrupt file.
- Sheet names are validated (length, forbidden characters, characters XML cannot hold such as
  U+FFFE, case-insensitive duplicates) before anything is written.
- `Style` is immutable; equal styles share one entry in the file.
- Floats are written in the shortest form that reads back as the same number (`0.1` stays `0.1`,
  `1.0E+25` uses an exponent), independent of the `precision` and `serialize_precision` settings;
  `-0.0` is written as `0`. Integers are written exactly, but Excel and LibreOffice store numbers as
  doubles: integers beyond 2^53 (9,007,199,254,740,992) lose precision and Excel shows 15 significant
  digits, so write IDs and account numbers as strings.
- Column widths are in characters of the default font (11 pt Calibri). The cell padding is added
  as the OOXML specification prescribes, so Excel for Windows shows the width you set; Excel for Mac
  renders 0.17 narrower (32 shows as 31.83, checked with Excel for Mac 16).
- A column holding dates gets a width that fits its longest date, unless you set one: 11 for
  `yyyy-mm-dd`, 20 for `yyyy-mm-dd hh:mm:ss`, and long month or weekday names counted as 10
  characters. Without it Excel shows `#####` for a date wider than the default column. Other
  columns are not sized to their content.
- `close()` renames the finished file onto the target path, replacing an existing file without
  warning. If an earlier error aborted the write, `close()` throws `LogicException` and no file is
  written.

### CSV for Excel

`Spreadsheet::write('x.csv')` writes UTF-8 with `,`. Hungarian (and most continental) Excel
installations expect `;` and only recognise UTF-8 with a byte-order mark, so create the writer
directly for them:

```php
use IntrAgile\Spreadsheet\Csv\CsvWriter;

$writer = new CsvWriter('/path/to/export.csv', delimiter: ';', bom: true, escapeFormulas: true);
$writer->addRow(['Név', 'Összeg'])->addRow(['Árvíztűrő tükörfúrógép', 1234.5])->close();
```

Numbers are written with a `.` decimal point whatever the locale. A Hungarian Excel shows such a
field as text; if it should see a number, pass a string with a decimal comma
(`number_format($total, 2, ',', '')`). Negative numbers passed as strings are escaped by
`escapeFormulas` too, so leave the option off for such purely numeric exports.

**CSV injection.** A field that starts with `=`, `+`, `-` or `@` is run as a formula when the file
is opened in Excel or LibreOffice, which lets user-supplied text such as
`=HYPERLINK("https://evil.example", "click")` act inside the reader's spreadsheet. With
`escapeFormulas: true` every string value starting with one of those characters, a tab or a
carriage return is written with a leading `'`, so it is shown as text instead of being run. The
apostrophe stays visible: Excel hides a leading `'` only when it is typed into a cell, not when it
comes from a CSV file (checked with Excel for Mac 16). It is off by default because it changes the
data (a text `-5` becomes `'-5`); turn it on for any export that contains user input. Values passed
as `Cell::formula()` stay unescaped.

Excel also converts CSV fields that look like numbers or dates: `00123` becomes `123`, `1e5` becomes
`1,00E+05` and `2026-01-15` becomes a date. CSV cannot prevent this; write XLSX when such text must
survive.

## Office application compatibility

Checked on macOS 15.7 (Hungarian locale) with Microsoft Excel for Mac 16.114, Apple Numbers and
LibreOffice 26.8, using the sample set from `tests/office/build-samples.php` and the inspectors next
to it (AppleScript for Excel and Numbers, `libreoffice-inspect.sh` for LibreOffice).

Excel:

- XLSX and ODS (plain and styled) open without a repair prompt. Cell positions and types are right,
  including null gaps, dates, booleans, multi-line text and text that looks like a number or formula
  (`00123`, `1e5`, `=1+1`). Number and date formats, column widths, the frozen header row and the
  autofilter show in XLSX; formulas are calculated on open in both formats.
- ODS in Excel: the autofilter shows, the frozen row does not, because Excel's ODS support has no
  frozen panes: it ignores them in an ODS file saved by LibreOffice too.
- Date columns are at least as wide as Excel's AutoFit makes them, so no date shows as `#####`.
- CSV with `delimiter: ';', bom: true` opens split into columns with correct accents; with `,` it
  does not split on a Hungarian system.
- Files saved by Excel (XLSX, and CSV in the Mac code page with CRLF or CR line endings) read back
  with the right values and types.

LibreOffice:

- XLSX and ODS open with the right values, types and formats; the frozen header row and the
  autofilter show in both formats, and date columns are at least as wide as Calc's optimal width.

Apple Numbers:

- XLSX and CSV open with the right values, types, number and date formats, and calculated formulas.
- Numbers cannot open ODS files at all, and it shows no frozen rows from XLSX, Excel's own files
  included.

## Memory

No reader or writer holds more than the current row in memory, plus caches of fixed size. XLSX
shared strings are kept in RAM up to `ReadOptions::$sharedStringsInMemory` entries; the rest spill
to a temporary file together with a fixed-width index of their offsets, so memory stays constant
however many strings spill (only 64 KiB read-ahead blocks are held). Writers stream to a temporary file and rename it into place on
`close()`. The test suite asserts that peak memory does not grow with the row count.

## Safety limits

Uploaded files are untrusted, so readers enforce limits (all configurable in `ReadOptions`):

| Option | Default | Protects against |
|---|---|---|
| `maxZipEntries` | 5,000 | archives with huge entry counts |
| `maxUncompressedBytes` | 4 GiB | zip bombs, each entry is also capped at its declared size (at least 1 MiB) on the bytes actually decompressed |
| `maxCompressionRatio` | 200 | entries larger than 1 MiB that expand more than 200:1 |
| `maxCellBytes` | 1 MiB | one cell or CSV field growing without bound: ODS space runs (`text:s`), every XLSX text value (shared, inline, formula results, untyped), a CSV field after a stray quote |
| `maxCells` | 100,000,000 | ODS cells produced by repeating cells and rows (`number-columns-repeated` x `number-rows-repeated`) |
| `maxSharedStrings` | 10,000,000 | XLSX shared-string tables with millions of entries |

Fixed limits: non-empty rows beyond 1,048,576 (empty rows past it are skipped; ODS repeat counts
are held to the limits however many digits they have) and XLSX columns beyond XFD (16,384); CSV records with more
than 16,384 fields; more than 4,096 sheets; more than 65,536 cell or number formats in an XLSX
styles part; 64 MiB for the XLSX workbook, relationship and styles parts. Each raises
`LimitExceeded` instead of exhausting memory or time. A DOCTYPE with an internal subset (where
entities are declared) is refused, and external DTDs and entities are never loaded, so there is no
XXE or entity expansion. Unsafe zip entry names are rejected, and an entry whose header cannot be
read makes the archive `CorruptFile`. Limit
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
