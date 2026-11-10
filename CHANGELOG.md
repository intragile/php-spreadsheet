# Changelog

Developed and maintained by [IntrAgile Kft.](https://intragile.eu)

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-11-10

First stable release: streaming XLSX, ODS and CSV reading and writing with bounded memory.

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
- CSV: UTF-16 and UTF-32 are detected without a byte-order mark, UTF-32 marks are recognised, and
  Excel for Mac exports are read (Mac Central European code page, bare carriage-return line endings).
- XLSX and ODS writers give a column holding dates a width that fits its longest date, unless the
  column has a width of its own, so Excel and LibreOffice no longer show `#####`.

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
- `ReadOptions` is a readonly class: limits cannot be changed after validation.
- XLSX column widths include the cell padding the OOXML specification prescribes (a width of 12 is
  written as `12.7109375`), so Excel shows the width that was set, as LibreOffice already did for ODS.
- A missing or unreadable path throws `SpreadsheetException` from every reader (the zip readers
  used to say `CorruptFile`); a 0-byte file throws `UnsupportedFormat`; a truncated zip throws
  `CorruptFile`; `.xlsb` and password-protected Office files are named in the `UnsupportedFormat`
  message.
- Writers: `close()` after an aborted write throws `LogicException` instead of returning without a
  file; floats are written in the shortest round-trip form independent of `serialize_precision`, and
  `-0.0` as `0`; sheet names with characters XML cannot hold are rejected.
- XLSX: empty rows beyond row 1,048,576 are skipped; only a non-empty one raises `LimitExceeded`
  ("A non-empty row lies beyond row 1048576.", as for ODS).
- ODS: a repeat count longer than 12 digits on content raises `LimitExceeded` instead of
  `CorruptFile`; number formats such as `0.##` keep their optional decimals optional
  (`number:min-decimal-places`).

### Fixed

- CSV files in Windows-1250 with a byte the code page does not define (such as `0x81`) lost every
  row of the affected chunk behind a silenced warning; decoding is now lossless (`U+FFFD`) for
  single-byte code pages, UTF-16 and UTF-32, and never raises a warning.
- XLSX shared strings spilled to disk no longer keep an 8-byte offset per entry in memory; the
  offsets live in a fixed-width temporary file.
- ISO 8601 date values with a trailing newline are no longer accepted.
- A `csvEncoding` without byte order (`UTF-16`, `UCS-2`, `UTF-32`, `UCS-4`) on a file with a
  byte-order mark decoded little-endian files to garbage; the mark now decides the byte order. Without
  a mark, the byte order detected from the content is used.
- ODS: Excel ignored the autofilter of written files; the database range is now unnamed and the
  `of` formula namespace is declared.
- ODS: LibreOffice ignored the frozen rows of written files because the `ooo` namespace of the view
  settings was undeclared.
- XLSX formula string results (`t="str"`) decode `_xHHHH_` escapes like other strings.
- Closing one XML reader no longer discards libxml errors still owed to another open reader, and a
  reader left in the middle of a step no longer leaves its errors behind for the next one.
- ODS spaces next to tabs are written as `text:s`, so whitespace collapsing cannot drop them.

### Security

- XLSX and ODS rows beyond 1,048,576 raise `LimitExceeded`; trailing empty ODS row repeats are skipped.
- ODS: the total text of a cell (expanded `text:s` runs included) is capped by `maxCellBytes`, and
  cells produced by column and row repeats by `maxCells`; a 502-byte file could previously build a
  104 MB cell, and repeated rows of repeated cells meant ~7·10^10 operations.
- XLSX: cells without a reference may not run past column XFD; shared and inline strings obey
  `maxCellBytes`; the shared-string table obeys `maxSharedStrings`; workbook, relationship and styles
  parts are capped at 64 MiB, cell and number formats at 65,536.
- XLSX and ODS: at most 4,096 sheets.
- ODS writer: a row from a generator stops at the first cell past column 16,384 instead of
  buffering the whole row first.
- CSV: a field longer than `maxCellBytes` (such as the rest of the file after a stray quote) or a
  record with more than 16,384 fields throws `LimitExceeded` before it is buffered.
- CSV: a vertical tab, form feed or carriage return before a stray quote no longer bypasses the
  field limit; a small `maxCellBytes` no longer slows quoted input down four- to fivefold.
- XLSX: non-numeric untyped cell values obey `maxCellBytes`.
- Date serials far outside the calendar no longer raise a PHP warning.
- A zip entry whose header cannot be read makes the archive `CorruptFile` instead of being skipped.
- The CI workflow runs with a read-only `contents` token; the bench directory is left out of the
  Composer dist archive.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
