# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Package scaffold with a hardened zip and XML reading layer.
- `Spreadsheet::read()` and `Spreadsheet::write()` entry points with content-based format
  detection and extension-based writer selection.
- `ReadOptions::$maxCompressionRatio` (default 200) limiting how far a zip entry larger than 1 MiB may expand.
- `ReadOptions::$maxCellBytes` (default 1 MiB), `$maxCells` (default 100,000,000) and
  `$maxSharedStrings` (default 10,000,000), appended after the existing parameters. `ReadOptions`
  rejects non-positive limits with `InvalidArgumentException`.
- `CsvWriter` option `escapeFormulas` (off by default) against CSV injection: string values starting
  with `=`, `+`, `-`, `@`, tab or carriage return get a leading apostrophe.
- Benchmark scenario R1S: reading an XLSX file with shared strings built by a neutral generator.

### Changed

- `ReadOptions::$maxUncompressedBytes` now defaults to 4 GiB; large legitimate ODS files exceeded 256 MB.
- Writing `.xlsm` throws `UnsupportedFormat` (the workbook would hold no macros); reading is unchanged.
- `Style::numberFormat()` rejects empty and invalid UTF-8 codes and `Cell::formula()` a bare `=`
  with `InvalidArgumentException`; `CsvWriter` rejects `INF`, `NAN` and unsupported value types the
  same way the XLSX and ODS writers do.
- A date-formatted XLSX cell whose serial lies outside 0..2958465.99999 is returned as the number.
- Frozen XLSX panes are written in Excel's own form (`activePane` plus a `selection`); ODS number and
  boolean cells carry a `text:p` display copy.
- Resuming a CSV row generator after `close()` throws `LogicException`, as for XLSX and ODS.

### Fixed

- CSV files in Windows-1250 with a byte the code page does not define (such as `0x81`) lost every
  row of the affected chunk behind a silenced warning; decoding is now lossless (`U+FFFD`) for
  single-byte code pages, UTF-16 and UTF-32, and never raises a warning.
- XLSX shared strings spilled to disk no longer keep an 8-byte offset per entry in memory; the
  offsets live in a fixed-width temporary file.
- ISO 8601 date values with a trailing newline are no longer accepted.

### Security

- XLSX and ODS rows beyond 1,048,576 raise `LimitExceeded`; trailing empty ODS row repeats are skipped.
- ODS: the total text of a cell (expanded `text:s` runs included) is capped by `maxCellBytes`, and
  cells produced by column and row repeats by `maxCells`; a 502-byte file could previously build a
  104 MB cell, and repeated rows of repeated cells meant ~7·10^10 operations.
- XLSX: cells without a reference may not run past column XFD; shared and inline strings obey
  `maxCellBytes`; the shared-string table obeys `maxSharedStrings`; workbook, relationship and styles
  parts are capped at 64 MiB, cell and number formats at 65,536.
- XLSX and ODS: at most 4,096 sheets.
- CSV: a field longer than `maxCellBytes` (such as the rest of the file after a stray quote) or a
  record with more than 16,384 fields throws `LimitExceeded` before it is buffered.
- Date serials far outside the calendar no longer raise a PHP warning.
- A zip entry whose header cannot be read makes the archive `CorruptFile` instead of being skipped.
- The CI workflow runs with a read-only `contents` token; the bench directory is left out of the
  Composer dist archive.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
