# Benchmark results

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

## Machine

- PHP: `PHP 8.5.0 (cli) (built: Nov 18 2025 08:02:20) (NTS)`
- OS: macOS 15.7.3 (arm64)
- CPU: Apple M1, 8 cores
- RAM: 16 GB
- Data files (var-dir): /Users/komcsi/bench-var on /dev/disk3s5 (mounted at /System/Volumes/Data) [File System Personality APFS, Protocol Apple Fabric, Device Location Internal, Solid State Yes]
- Libraries: `maatwebsite/excel` 4.0.6, `spatie/simple-excel` 3.11.0, `openspout/openspout` v5.13.0, `phpoffice/phpspreadsheet` 5.11.0, `intragile/php-spreadsheet` (path repository, working tree)
- Each case runs in a fresh `php -d memory_limit=2G` process with a hard wall-clock limit. With repeats, the library order is rotated on every repetition.

## How to read the tables

- **Seconds**: wall time of the operation only (`hrtime`), after the process and (for maatwebsite) the Laravel application were booted. Median over the runs, minimum in parentheses.
- **Peak MB**: `memory_get_peak_usage(true)` during the operation (the peak counter is reset after boot). **Over baseline**: the same minus `memory_get_usage(true)` right after boot, so framework overhead does not distort the comparison. **RSS MB**: `getrusage` max resident set sampled right after the timed region; it also covers native memory (zip, libxml) that PHP's allocator does not count.
- **Runs**: successful runs / total runs. `OOM`, `timeout` and `FAILED` are written out; nothing is omitted. `not run` means the case was not part of this run.

## W1 Write XLSX, plain (10 columns)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 100000 | generator only (floor) | ok | 3/3 | 0.13 (0.13) | 4.0 (4.0) | 0.0 (0.0) | 28.1 (28.0) | 787 402 |
| 100000 | intragile/php-spreadsheet | ok | 3/3 | 1.10 (1.08) | 4.0 (4.0) | 0.0 (0.0) | 29.4 (29.1) | 91 158 |
| 100000 | spatie/simple-excel | ok | 3/3 | 5.09 (5.03) | 6.0 (6.0) | 2.0 (2.0) | 79.9 (79.7) | 19 639 |
| 100000 | maatwebsite/excel | ok | 3/3 | 17.34 (17.17) | 730.9 (730.9) | 714.9 (714.9) | 737.7 (737.2) | 5 767 |
| 1000000 | generator only (floor) | ok | 1/1 | 1.24 | 4.0 | 0.0 | 28.2 | 807 103 |
| 1000000 | intragile/php-spreadsheet | ok | 1/1 | 12.31 | 4.0 | 0.0 | 29.2 | 81 241 |
| 1000000 | spatie/simple-excel | ok | 1/1 | 63.44 | 6.0 | 2.0 | 530.6 | 15 763 |
| 1000000 | maatwebsite/excel | OOM (OOM: Allowed memory size of 2147483648 bytes exhausted (tried to allocate 4096 bytes)) | 0/1 | n/a | n/a | n/a | n/a | n/a |

- All libraries write the same 10 columns from the same lazy generator. The "generator only" row is the cost of producing the rows with no library involved, i.e. the floor every library pays.
- Dates: ours writes a native date cell (`yyyy-mm-dd`). OpenSpout emits a bare serial for a date cell without a style, so for spatie the date column is built as an explicit OpenSpout `Row` whose `DateTimeCell` carries a `yyyy-mm-dd` style (`SimpleExcelWriter::addRow` accepts `Row` objects). maatwebsite has no date cell type: the column is written as a serial plus a `yyyy-mm-dd` column format (`WithColumnFormatting`). All three files therefore contain real, formatted date cells.
- maatwebsite uses `FromGenerator` with `WithStrictNullComparison` (without it Laravel Excel writes `false` as an empty cell, which the output verification caught). `WithCustomChunkSize` only affects query and scout exports, so it is a no-op for generators and is not used. PhpSpreadsheet keeps all cells of the sheet in memory until the file is saved.
- spatie/OpenSpout writes with `noHeaderRow()`. Building the explicit OpenSpout `Row` (one cell object per value, a `DateTimeCell` for the date) happens per row inside the timed region, as an application using spatie would have to do it; it is part of spatie's measured time.
- Every written file is re-read with the IntrAgile reader after the timed region and compared with the generator on the first, middle and last row and every 997th row, all 10 columns, with strict types (date as date, bool as bool, numbers as numbers). A mismatch makes the case `FAILED`.

## W2 Write XLSX, styled (header bold + fill + border, number formats on 2 columns, column widths)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 100000 | generator only (floor) | ok | 3/3 | 0.14 (0.12) | 4.0 (4.0) | 0.0 (0.0) | 27.9 (27.8) | 699 301 |
| 100000 | intragile/php-spreadsheet | ok | 3/3 | 1.21 (1.17) | 4.0 (4.0) | 0.0 (0.0) | 29.2 (28.9) | 82 850 |
| 100000 | spatie/simple-excel (partial) | ok | 3/3 | 5.29 (4.99) | 6.0 (6.0) | 2.0 (2.0) | 79.7 (79.5) | 18 907 |
| 100000 | maatwebsite/excel | ok | 3/3 | 18.45 (18.04) | 733.2 (733.2) | 717.2 (717.2) | 740.0 (739.8) | 5 422 |
| 1000000 | generator only (floor) | ok | 1/1 | 1.40 | 4.0 | 0.0 | 27.9 | 715 820 |
| 1000000 | intragile/php-spreadsheet | ok | 1/1 | 14.90 | 4.0 | 0.0 | 29.0 | 67 110 |
| 1000000 | spatie/simple-excel (partial) | ok | 1/1 | 65.04 | 6.0 | 2.0 | 534.0 | 15 374 |
| 1000000 | maatwebsite/excel | OOM (OOM: Allowed memory size of 2147483648 bytes exhausted (tried to allocate 4096 bytes)) | 0/1 | n/a | n/a | n/a | n/a | n/a |

- **spatie is partial**: it applies the header style (bold, fill, border) and the date format, but NOT the number formats on the two float columns and NOT the column widths, so it does less work than the other two.
- ours: header `Style` row, `Cell::of` with a number format on columns 2 and 8, column widths for all 10 columns. maatwebsite: `WithStyles` (header row), `WithColumnFormatting` (columns B, E, H), `WithColumnWidths`. spatie builds its per-cell OpenSpout `Row` inside the timed region, as in W1.
- The same strict output verification as W1 applies (the header row is checked too).

## W3 Write CSV

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 100000 | generator only (floor) | ok | 3/3 | 0.12 (0.12) | 4.0 (4.0) | 0.0 (0.0) | 28.2 (27.8) | 813 008 |
| 100000 | intragile/php-spreadsheet | ok | 3/3 | 0.72 (0.70) | 4.0 (4.0) | 0.0 (0.0) | 27.8 (27.7) | 138 889 |
| 100000 | spatie/simple-excel | ok | 3/3 | 1.21 (1.17) | 4.0 (4.0) | 0.0 (0.0) | 27.9 (27.8) | 82 919 |
| 100000 | maatwebsite/excel | ok | 3/3 | 9.98 (9.91) | 612.0 (612.0) | 596.0 (596.0) | 632.6 (632.5) | 10 024 |
| 1000000 | generator only (floor) | ok | 1/1 | 1.25 | 4.0 | 0.0 | 28.0 | 800 641 |
| 1000000 | intragile/php-spreadsheet | ok | 1/1 | 7.45 | 4.0 | 0.0 | 28.1 | 134 192 |
| 1000000 | spatie/simple-excel | ok | 1/1 | 11.98 | 4.0 | 0.0 | 28.0 | 83 500 |
| 1000000 | maatwebsite/excel | OOM (OOM: Allowed memory size of 2147483648 bytes exhausted (tried to allocate 4096 bytes)) | 0/1 | n/a | n/a | n/a | n/a | n/a |

- The CSV forms differ per library and are verified per library: ours and maatwebsite write dates as `Y-m-d`, spatie (OpenSpout) writes `DATE_ATOM` (`2021-11-24T00:00:00+00:00`); booleans are `1`/`0` for ours and spatie and `TRUE`/`FALSE` for maatwebsite. These are the libraries' defaults, so the files are not byte-identical.
- ours and spatie stream to the file; maatwebsite builds a PhpSpreadsheet sheet first.

## R1 Read XLSX (input produced by the IntrAgile writer)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 100000 | intragile/php-spreadsheet | ok | 3/3 | 1.69 (1.57) | 6.0 (6.0) | 2.0 (2.0) | 29.7 (29.4) | 59 312 |
| 100000 | spatie/simple-excel | ok | 3/3 | 9.08 (9.05) | 6.0 (6.0) | 2.0 (2.0) | 29.9 (29.9) | 11 018 |
| 100000 | maatwebsite/excel | ok | 3/3 | 274.60 (274.33) | 112.5 (112.5) | 96.5 (96.5) | 2586.7 (2452.3) | 364 |
| 1000000 | intragile/php-spreadsheet | ok | 1/1 | 16.77 | 6.0 | 2.0 | 29.1 | 59 641 |
| 1000000 | spatie/simple-excel | ok | 1/1 | 90.40 | 6.0 | 2.0 | 29.9 | 11 062 |
| 1000000 | maatwebsite/excel | timeout (timeout after 600s) | 0/1 | n/a | n/a | n/a | n/a | n/a |

- The input file is produced once by the IntrAgile writer (cell references on every cell, no `<dimension>`, inline strings). X1 checks that all three libraries read this shape with the same row count and identical values; the cached input is regenerated whenever the writer sources change.
- Every library iterates all rows; the checksum (row count and sum of the id column) is computed in the callback and verified, so lazily-skipped parsing would be detected.
- maatwebsite reads with `OnEachRow` + `WithChunkReading(1000)` (raw values, no number-format rendering, no models or database). spatie reads with `noHeaderRow()` so it returns the same number of rows.

## R1S Read XLSX with shared strings (input produced by a neutral generator)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 100000 | intragile/php-spreadsheet | ok | 3/3 | 1.79 (1.72) | 12.0 (12.0) | 8.0 (8.0) | 36.5 (36.4) | 55 772 |
| 100000 | spatie/simple-excel | ok | 3/3 | 55.80 (55.70) | 8.0 (8.0) | 4.0 (4.0) | 32.3 (32.3) | 1 792 |
| 100000 | maatwebsite/excel | ok | 3/3 | 278.14 (276.96) | 122.6 (122.6) | 106.6 (106.6) | 2457.9 (2429.1) | 360 |
| 1000000 | intragile/php-spreadsheet | ok | 1/1 | 25.52 | 14.0 | 8.0 | 37.0 | 39 193 |
| 1000000 | spatie/simple-excel | timeout (timeout after 600s) | 0/1 | n/a | n/a | n/a | n/a | n/a |
| 1000000 | maatwebsite/excel | timeout (timeout after 600s) | 0/1 | n/a | n/a | n/a | n/a | n/a |

- Real Excel, Google Sheets and LibreOffice files keep their text in `xl/sharedStrings.xml`, while the R1 input (from the IntrAgile writer) uses inline strings only. The R1S input is written by `NeutralXlsxWriter` in `generate.php`, which uses none of the three libraries: plain XML streamed into a zip with a fully deduplicated shared-string table (with `count` and `uniqueCount`), a cell reference on every cell, dates as serials with the built-in date format 14 and booleans as `t="b"`. Same 10 columns and values as R1.
- Every library reads every row; besides the row count and id checksum, the sampled rows (first, middle, last, every 997th) are compared cell by cell with the generator in the callback (a date may come back as a date or as its serial, numbers numerically). The expected rows are prepared before the memory baseline; the comparison of about 0.1% of the rows is inside the timed region for every library alike.
- At 1,000,000 rows the table holds about two million unique strings, so the IntrAgile reader keeps the first 100,000 in memory and spills the rest to a temporary file (`ReadOptions::$sharedStringsInMemory`).

## R2 Read CSV (input produced by the IntrAgile writer)

| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|---:|
| 100000 | intragile/php-spreadsheet | ok | 3/3 | 0.24 (0.24) | 4.0 (4.0) | 0.0 (0.0) | 28.0 (27.8) | 414 938 |
| 100000 | spatie/simple-excel | ok | 3/3 | 0.86 (0.86) | 6.0 (6.0) | 2.0 (2.0) | 28.7 (28.7) | 115 875 |
| 100000 | maatwebsite/excel | ok | 3/3 | 71.02 (71.00) | 30.0 (30.0) | 14.0 (14.0) | 54.6 (54.1) | 1 408 |
| 1000000 | intragile/php-spreadsheet | ok | 1/1 | 3.01 | 4.0 | 0.0 | 28.4 | 332 226 |
| 1000000 | spatie/simple-excel | ok | 1/1 | 9.19 | 6.0 | 2.0 | 28.6 | 108 849 |
| 1000000 | maatwebsite/excel | timeout (timeout after 600s) | 0/1 | n/a | n/a | n/a | n/a | n/a |

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
