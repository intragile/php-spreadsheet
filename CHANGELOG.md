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

### Changed

- `ReadOptions::$maxUncompressedBytes` now defaults to 4 GiB; large legitimate ODS files exceeded 256 MB.

### Security

- XLSX and ODS rows beyond 1,048,576 raise `LimitExceeded`; trailing empty ODS row repeats are skipped.

---

Developed and maintained by [IntrAgile Kft.](https://intragile.eu).
