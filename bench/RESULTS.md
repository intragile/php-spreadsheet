# Benchmark results

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

> **SMOKE RUN: not the benchmark.** These numbers come from tiny inputs (2,000 rows) that only prove every case runs end to end. Process start-up and framework boot dominate; do not read anything into them. The full 100k / 1M matrix replaces this file.

## Machine

- PHP: `PHP 8.5.0 (cli) (built: Nov 18 2025 08:02:20) (NTS)`
- OS: macOS 15.7.3 (arm64)
- CPU: Apple M1, 8 cores
- RAM: 16 GB
- Data files (var-dir): /private/tmp/claude-501/-Volumes-KINGSTON-dev-intragile-packagist/ce26964b-dc4a-48eb-a46d-1c62d9f6a063/scratchpad/smoke-var on /dev/disk3s5 (mounted at /System/Volumes/Data) [File System Personality APFS, Protocol Apple Fabric, Device Location Internal, Solid State Yes]
- Libraries: `maatwebsite/excel` 4.0.6, `spatie/simple-excel` 3.11.0, `openspout/openspout` v5.13.0, `phpoffice/phpspreadsheet` 5.11.0, `intragile/php-spreadsheet` (path repository, working tree)
- Each case runs in a fresh `php -d memory_limit=2G` process with a hard wall-clock limit. With repeats, the library order is rotated on every repetition.

## How to read the tables

- **Seconds**: wall time of the operation only (`hrtime`), after the process and (for maatwebsite) the Laravel application were booted. Median over the runs, minimum in parentheses.
- **Peak MB**: `memory_get_peak_usage(true)` during the operation (the peak counter is reset after boot). **Over baseline**: the same minus `memory_get_usage(true)` right after boot, so framework overhead does not distort the comparison. **RSS MB**: `getrusage` max resident set sampled right after the timed region; it also covers native memory (zip, libxml) that PHP's allocator does not count.
- **Runs**: successful runs / total runs. `OOM`, `timeout` and `FAILED` are written out; nothing is omitted. `not run` means the case was not part of this run.

## W1 Write XLSX, plain (10 columns)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 2000 | generator only (floor) | ok | 3/3 | 0.00 (0.00) | 4.0 (4.0) | 0.0 (0.0) | 27.8 (27.8) | 666 667 |
| 2000 | intragile/php-spreadsheet | ok | 3/3 | 0.03 (0.03) | 4.0 (4.0) | 0.0 (0.0) | 29.2 (28.8) | 58 824 |
| 2000 | spatie/simple-excel | ok | 3/3 | 0.14 (0.13) | 6.0 (6.0) | 2.0 (2.0) | 29.9 (29.3) | 14 184 |
| 2000 | maatwebsite/excel | ok | 3/3 | 0.40 (0.37) | 54.0 (54.0) | 38.0 (38.0) | 63.0 (62.7) | 5 013 |

- All libraries write the same 10 columns from the same lazy generator. The "generator only" row is the cost of producing the rows with no library involved, i.e. the floor every library pays.
- Dates: ours writes a native date cell (`yyyy-mm-dd`). OpenSpout emits a bare serial for a date cell without a style, so for spatie the date column is built as an explicit OpenSpout `Row` whose `DateTimeCell` carries a `yyyy-mm-dd` style (`SimpleExcelWriter::addRow` accepts `Row` objects). maatwebsite has no date cell type: the column is written as a serial plus a `yyyy-mm-dd` column format (`WithColumnFormatting`). All three files therefore contain real, formatted date cells.
- maatwebsite uses `FromGenerator` with `WithStrictNullComparison` (without it Laravel Excel writes `false` as an empty cell, which the output verification caught). `WithCustomChunkSize` only affects query and scout exports, so it is a no-op for generators and is not used. PhpSpreadsheet keeps all cells of the sheet in memory until the file is saved.
- spatie/OpenSpout writes with `noHeaderRow()`. Building the explicit OpenSpout `Row` (one cell object per value, a `DateTimeCell` for the date) happens per row inside the timed region, as an application using spatie would have to do it; it is part of spatie's measured time.
- Every written file is re-read with the IntrAgile reader after the timed region and compared with the generator on the first, middle and last row and every 997th row, all 10 columns, with strict types (date as date, bool as bool, numbers as numbers). A mismatch makes the case `FAILED`.

## W2 Write XLSX, styled (header bold + fill + border, number formats on 2 columns, column widths)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 2000 | generator only (floor) | ok | 3/3 | 0.00 (0.00) | 4.0 (4.0) | 0.0 (0.0) | 28.0 (27.9) | 666 667 |
| 2000 | intragile/php-spreadsheet | ok | 3/3 | 0.06 (0.04) | 4.0 (4.0) | 0.0 (0.0) | 29.3 (29.2) | 36 364 |
| 2000 | spatie/simple-excel (partial) | ok | 3/3 | 0.12 (0.12) | 6.0 (6.0) | 2.0 (2.0) | 30.1 (29.8) | 16 129 |
| 2000 | maatwebsite/excel | ok | 3/3 | 0.63 (0.42) | 54.0 (54.0) | 38.0 (38.0) | 63.5 (62.5) | 3 175 |

- **spatie is partial**: it applies the header style (bold, fill, border) and the date format, but NOT the number formats on the two float columns and NOT the column widths, so it does less work than the other two.
- ours: header `Style` row, `Cell::of` with a number format on columns 2 and 8, column widths for all 10 columns. maatwebsite: `WithStyles` (header row), `WithColumnFormatting` (columns B, E, H), `WithColumnWidths`. spatie builds its per-cell OpenSpout `Row` inside the timed region, as in W1.
- The same strict output verification as W1 applies (the header row is checked too).

## W3 Write CSV

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 2000 | generator only (floor) | ok | 3/3 | 0.00 (0.00) | 4.0 (4.0) | 0.0 (0.0) | 28.5 (28.2) | 666 667 |
| 2000 | intragile/php-spreadsheet | ok | 3/3 | 0.02 (0.02) | 4.0 (4.0) | 0.0 (0.0) | 28.8 (28.1) | 105 263 |
| 2000 | spatie/simple-excel | ok | 3/3 | 0.04 (0.03) | 4.0 (4.0) | 0.0 (0.0) | 28.7 (28.1) | 57 143 |
| 2000 | maatwebsite/excel | ok | 3/3 | 0.29 (0.26) | 34.0 (34.0) | 18.0 (18.0) | 58.2 (56.8) | 6 849 |

- The CSV forms differ per library and are verified per library: ours and maatwebsite write dates as `Y-m-d`, spatie (OpenSpout) writes `DATE_ATOM` (`2021-11-24T00:00:00+00:00`); booleans are `1`/`0` for ours and spatie and `TRUE`/`FALSE` for maatwebsite. These are the libraries' defaults, so the files are not byte-identical.
- ours and spatie stream to the file; maatwebsite builds a PhpSpreadsheet sheet first.

## R1 Read XLSX (input produced by the IntrAgile writer)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 3/3 | 0.05 (0.04) | 6.0 (6.0) | 2.0 (2.0) | 29.6 (29.2) | 43 478 |
| 2000 | spatie/simple-excel | ok | 3/3 | 0.23 (0.23) | 6.0 (6.0) | 2.0 (2.0) | 30.1 (30.0) | 8 696 |
| 2000 | maatwebsite/excel | ok | 3/3 | 0.58 (0.53) | 30.0 (30.0) | 14.0 (14.0) | 96.6 (94.7) | 3 442 |

- The input file is produced once by the IntrAgile writer (cell references on every cell, no `<dimension>`, inline strings). X1 checks that all three libraries read this shape with the same row count and identical values; the cached input is regenerated whenever the writer sources change.
- Every library iterates all rows; the checksum (row count and sum of the id column) is computed in the callback and verified, so lazily-skipped parsing would be detected.
- maatwebsite reads with `OnEachRow` + `WithChunkReading(1000)` (raw values, no number-format rendering, no models or database). spatie reads with `noHeaderRow()` so it returns the same number of rows.

## R1S Read XLSX with shared strings (input produced by a neutral generator)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 1/1 | 0.09 | 6.0 | 2.0 | 31.0 | 23 529 |
| 2000 | spatie/simple-excel | ok | 1/1 | 0.43 | 6.0 | 2.0 | 31.4 | 4 640 |
| 2000 | maatwebsite/excel | ok | 1/1 | 1.01 | 30.0 | 14.0 | 99.3 | 1 988 |

- Real Excel, Google Sheets and LibreOffice files keep their text in `xl/sharedStrings.xml`, while the R1 input (from the IntrAgile writer) uses inline strings only. The R1S input is written by `NeutralXlsxWriter` in `generate.php`, which uses none of the three libraries: plain XML streamed into a zip with a fully deduplicated shared-string table (with `count` and `uniqueCount`), a cell reference on every cell, dates as serials with the built-in date format 14 and booleans as `t="b"`. Same 10 columns and values as R1.
- Every library reads every row; besides the row count and id checksum, the sampled rows (first, middle, last, every 997th) are compared cell by cell with the generator in the callback (a date may come back as a date or as its serial, numbers numerically). The expected rows are prepared before the memory baseline; the comparison of about 0.1% of the rows is inside the timed region for every library alike.
- At 1,000,000 rows the table holds about two million unique strings, so the IntrAgile reader keeps the first 100,000 in memory and spills the rest to a temporary file (`ReadOptions::$sharedStringsInMemory`).

## R2 Read CSV (input produced by the IntrAgile writer)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 3/3 | 0.01 (0.01) | 4.0 (4.0) | 0.0 (0.0) | 28.1 (28.0) | 333 333 |
| 2000 | spatie/simple-excel | ok | 3/3 | 0.02 (0.02) | 6.0 (6.0) | 2.0 (2.0) | 28.8 (28.1) | 83 333 |
| 2000 | maatwebsite/excel | ok | 3/3 | 0.38 (0.36) | 28.0 (28.0) | 12.0 (12.0) | 53.2 (52.4) | 5 208 |

- CSV input produced by the IntrAgile writer; comma-separated, UTF-8. Delimiter and encoding are not given to any library (ours sniffs, the others use their defaults).
- Values are compared as strings; the checksum is the sum of the id column.

## X1 Cross-reader correctness (10,000 rows)

Three fixtures are written by the IntrAgile writer: a plain file and a styled file, both with null gaps in the middle of a row (and at the end of some rows), and the R1 input shape without gaps. Each library reads all three and every cell of every row is compared with the generated data (not only samples; the first row, a middle row, the last row and the cell right after a gap are included). The fixtures have no `<dimension>` element.

| Library | Verdict | plain + gaps | styled + gaps | R1 input |
|---|---|---|---|---|
| intragile/php-spreadsheet | ok | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells |
| spatie/simple-excel | ok | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells |
| maatwebsite/excel | ok | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells |

## Scenario equivalence summary

- Same data: one deterministic generator (isolated `Random\Randomizer` with `Mt19937` seeded 20261109), identical values for every library, produced lazily.
- spatie is not booted through Laravel (it does not need it); maatwebsite is booted through Orchestra Testbench, and the boot cost is excluded from both time and the over-baseline memory figure.
- spatie W2 is partial (header style and date format only).
- Dates are real date cells in every xlsx file (see the W1 notes for how each library gets there); in CSV each library writes its own default date and boolean forms.
- The input files of R1/R2 come from the IntrAgile writer; X1 shows whether the other two libraries read that shape correctly. The R1S input comes from a neutral generator with shared strings, the layout real spreadsheet applications write.
- spatie's per-cell OpenSpout `Row` building for xlsx writes is inside its timed region.
- Output files are verified with strict types on sampled rows across all 10 columns; any mismatch fails the case.

Reproduce with the commands in [README.md](README.md).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
