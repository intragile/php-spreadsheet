# Benchmark results

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

> **SMOKE RUN: not the benchmark.** These numbers come from tiny inputs (2,000 rows) that only prove every case runs end to end. Process start-up and framework boot dominate; do not read anything into them. The full 100k / 1M matrix replaces this file.

## Machine

- PHP: `PHP 8.5.0 (cli) (built: Nov 18 2025 08:02:20) (NTS)`
- OS: macOS 15.7.3 (arm64)
- CPU: Apple M1, 8 cores
- RAM: 16 GB
- Libraries: `maatwebsite/excel` 4.0.6, `spatie/simple-excel` 3.11.0, `openspout/openspout` v5.13.0, `phpoffice/phpspreadsheet` 5.11.0, `intragile/php-spreadsheet` (path repository, working tree)
- Each case runs in a fresh `php -d memory_limit=2G` process with a hard wall-clock limit; the file is verified afterwards with the IntrAgile reader (row count and id checksum) and a mismatch marks the case as failed.

## How to read the tables

- **Seconds**: wall time of the operation only (`hrtime`), after the process and (for maatwebsite) the Laravel application were booted.
- **Peak MB**: `memory_get_peak_usage(true)` of the whole process. **Over baseline**: the same minus `memory_get_usage(true)` right after boot, so framework overhead does not distort the comparison. **RSS MB**: `getrusage` max resident set, which also covers native memory (zip, libxml) that PHP's allocator does not count.
- `OOM`, `timeout` and `FAILED` are written out; nothing is omitted. `not run` means the case was not part of this run.

## W1 Write XLSX, plain (10 columns)

| Rows | Library | Status | Seconds | Peak MB | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 0.03 | 4.0 | 0.0 | 29.7 | 58 824 |
| 2000 | spatie/simple-excel | ok | 0.12 | 4.0 | 0.0 | 29.9 | 16 393 |
| 2000 | maatwebsite/excel | ok | 0.34 | 54.0 | 38.0 | 61.5 | 5 848 |

- All libraries write the same 10 columns from the same lazy generator (the generator cost is identical for everyone).
- maatwebsite has no date cell type: the date column is written as a serial number plus a `yyyy-mm-dd` column format (`WithColumnFormatting`), which the other two libraries do natively. That is extra work for maatwebsite that is necessary to produce an equivalent file.
- maatwebsite goes through `FromGenerator` + `WithCustomChunkSize(1000)`; PhpSpreadsheet still keeps all cells of the sheet in memory until the file is saved.
- spatie/OpenSpout writes list rows with `noHeaderRow()`.

## W2 Write XLSX, styled (header bold + fill + border, number formats on 2 columns, column widths)

| Rows | Library | Status | Seconds | Peak MB | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 0.03 | 4.0 | 0.0 | 29.7 | 80 000 |
| 2000 | spatie/simple-excel (partial) | ok | 0.11 | 6.0 | 2.0 | 29.9 | 18 182 |
| 2000 | maatwebsite/excel | ok | 0.33 | 54.0 | 38.0 | 62.5 | 6 042 |

- **spatie is partial**: it applies the header style (bold, fill, border) but NOT the number formats on 2 columns and NOT the column widths, so it does less work than the other two.
- ours: header `Style` row, `Cell::of` with a number format on columns 2 and 8, column widths for all 10 columns. maatwebsite: `WithStyles` (header row), `WithColumnFormatting` (columns B, E, H), `WithColumnWidths`.
- Both numeric formats and the date format are applied through the library API a normal user would use.

## W3 Write CSV

| Rows | Library | Status | Seconds | Peak MB | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 0.01 | 4.0 | 0.0 | 27.6 | 153 846 |
| 2000 | spatie/simple-excel | ok | 0.02 | 4.0 | 0.0 | 27.9 | 90 909 |
| 2000 | maatwebsite/excel | ok | 0.19 | 34.0 | 18.0 | 56.8 | 10 695 |

- maatwebsite writes the date as text `Y-m-d` (the others format dates themselves); booleans are written in each library's own CSV representation.
- spatie/OpenSpout and ours stream to the file; maatwebsite builds a PhpSpreadsheet sheet first.

## R1 Read XLSX (input produced by the IntrAgile writer)

| Rows | Library | Status | Seconds | Peak MB | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 0.03 | 6.0 | 2.0 | 28.6 | 62 500 |
| 2000 | spatie/simple-excel | ok | 0.17 | 6.0 | 2.0 | 29.0 | 11 561 |
| 2000 | maatwebsite/excel | ok | 0.41 | 30.0 | 14.0 | 82.9 | 4 914 |

- The input file is produced once by the IntrAgile writer (no `r` attributes on contiguous cells, no `<dimension>`, inline strings). This favours no library on parsing speed, but see X1: it was checked that all three libraries read it with the same row count and identical values.
- Every library iterates all rows; the checksum (row count and sum of the id column) is computed in the callback and verified, so lazily-skipped parsing would be detected.
- maatwebsite reads with `OnEachRow` + `WithChunkReading(1000)` (raw values, no number-format rendering, no models or database). spatie reads with `noHeaderRow()` so it returns the same number of rows.

## R2 Read CSV (input produced by the IntrAgile writer)

| Rows | Library | Status | Seconds | Peak MB | Over baseline MB | RSS MB | Rows/s |
|---:|---|---|---:|---:|---:|---:|---:|
| 2000 | intragile/php-spreadsheet | ok | 0.01 | 4.0 | 0.0 | 27.9 | 333 333 |
| 2000 | spatie/simple-excel | ok | 0.02 | 6.0 | 2.0 | 28.4 | 95 238 |
| 2000 | maatwebsite/excel | ok | 0.33 | 28.0 | 12.0 | 52.7 | 6 061 |

- CSV input produced by the IntrAgile writer; comma-separated, UTF-8. Delimiter and encoding are not given to any library (ours sniffs, the others use their defaults).
- Values are compared as strings; the checksum is the sum of the id column.

## X1 Cross-reader correctness (10,000 rows)

Three fixtures are written by the IntrAgile writer: a plain file and a styled file, both with null gaps in the middle of a row (and at the end of some rows), and the R1 input shape without gaps. Each library reads all three and every cell of every row is compared with the generated data (not only samples; the first row, a middle row, the last row and the cell right after a gap are included). The fixtures use cells without `r` attributes on contiguous cells and no `<dimension>` element.

| Library | Verdict | plain + gaps | styled + gaps | R1 input |
|---|---|---|---|---|
| intragile/php-spreadsheet | ok | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells |
| spatie/simple-excel | ok | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells | 10000 rows, 0 mismatched cells |
| maatwebsite/excel | MISMATCH | 10000 rows, 25240 mismatched cells | 10000 rows, 21763 mismatched cells | 10000 rows, 0 mismatched cells |

First mismatches for maatwebsite/excel:

- plain-gaps: row 3 col 4: expected null NULL, got bool false
- plain-gaps: row 3 col 5: expected date 2026-02-03 00:00:00, got int 486940
- plain-gaps: row 3 col 6: expected bool false, got float 536.4274
- plain-gaps: row 3 col 7: expected int 486940, got string 'njutgopdb twvxzyq'
- plain-gaps: row 3 col 8: expected float 536.4274, got string 'Row 3 / wqblntom'
- plain-gaps: row 3 col 9: expected string 'njutgopdb twvxzyq', got null NULL

Diagnosis (inspected by hand in the fixture XML and in PhpSpreadsheet's `Reader\Xlsx::loadSheetData`): the IntrAgile writer puts an `r` attribute only on the first cell after a gap and none on the cells that follow it. The spec allows that, and the IntrAgile reader and OpenSpout resynchronise on the explicit `r`, but PhpSpreadsheet (the engine behind maatwebsite/excel) counts cells without `r` positionally and does not resynchronise, so every cell after a gap lands in the wrong column. Files without gaps are read correctly by all three. This is a compatibility defect of the writer, not a benchmark artefact.

## Scenario equivalence summary

- Same data: one deterministic generator (`mt_srand(20261109)`), identical values for every library, produced lazily.
- spatie is not booted through Laravel (it does not need it); maatwebsite is booted through Orchestra Testbench, and the boot cost is excluded from both time and the over-baseline memory figure.
- spatie W2 is partial (header style only).
- maatwebsite writes dates as serial + format in xlsx and as text in csv; the other libraries write native dates.
- The input files of R1/R2 come from the IntrAgile writer; X1 shows whether the other two libraries read that shape correctly.

Reproduce with the commands in [README.md](README.md).

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)
