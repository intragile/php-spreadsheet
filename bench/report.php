<?php

declare(strict_types=1);

/**
 * Renders RESULTS.md from a results file written by run.php.
 *
 * Usage: php report.php [--in=results.json] [--out=RESULTS.md] [--smoke]
 */
$in = __DIR__.'/results.json';
$out = __DIR__.'/RESULTS.md';
$smoke = false;
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--in=')) {
        $in = substr($arg, 5);
    } elseif (str_starts_with($arg, '--out=')) {
        $out = substr($arg, 6);
    } elseif ($arg === '--smoke') {
        $smoke = true;
    }
}

/** All runs per scenario, row count and library, in file order. */
$results = [];
$storage = null;
foreach (file($in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $r = json_decode($line, true);
    if (is_array($r)) {
        $results[$r['scenario']][$r['rows']][$r['lib']][] = $r;
        $storage = $r['var_dir'] ?? $storage;
    }
}

function sh(string $cmd): string
{
    return trim((string) shell_exec($cmd.' 2>/dev/null'));
}

/** @param list<float|int> $values */
function median(array $values): ?float
{
    if ($values === []) {
        return null;
    }
    sort($values);
    $n = count($values);

    return $n % 2 === 1 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

$libs = ['none' => 'generator only (floor)', 'ours' => 'intragile/php-spreadsheet', 'spatie' => 'spatie/simple-excel', 'maatwebsite' => 'maatwebsite/excel'];
$realLibs = array_diff_key($libs, ['none' => 1]);
$versions = [];
$lock = json_decode((string) file_get_contents(__DIR__.'/composer.lock'), true);
foreach ($lock['packages'] as $package) {
    $versions[$package['name']] = $package['version'];
}

$scenarioTitles = [
    'W1' => 'W1 Write XLSX, plain (10 columns)',
    'W2' => 'W2 Write XLSX, styled (header bold + fill + border, number formats on 2 columns, column widths)',
    'W3' => 'W3 Write CSV',
    'R1' => 'R1 Read XLSX (input produced by the IntrAgile writer)',
    'R1S' => 'R1S Read XLSX with shared strings (input produced by a neutral generator)',
    'R2' => 'R2 Read CSV (input produced by the IntrAgile writer)',
];

$notes = [
    'W1' => [
        'All libraries write the same 10 columns from the same lazy generator. The "generator only" row is the cost of producing the rows with no library involved, i.e. the floor every library pays.',
        'Dates: ours writes a native date cell (`yyyy-mm-dd`). OpenSpout emits a bare serial for a date cell without a style, so for spatie the date column is built as an explicit OpenSpout `Row` whose `DateTimeCell` carries a `yyyy-mm-dd` style (`SimpleExcelWriter::addRow` accepts `Row` objects). maatwebsite has no date cell type: the column is written as a serial plus a `yyyy-mm-dd` column format (`WithColumnFormatting`). All three files therefore contain real, formatted date cells.',
        'maatwebsite uses `FromGenerator` with `WithStrictNullComparison` (without it Laravel Excel writes `false` as an empty cell, which the output verification caught). `WithCustomChunkSize` only affects query and scout exports, so it is a no-op for generators and is not used. PhpSpreadsheet keeps all cells of the sheet in memory until the file is saved.',
        'spatie/OpenSpout writes with `noHeaderRow()`. Building the explicit OpenSpout `Row` (one cell object per value, a `DateTimeCell` for the date) happens per row inside the timed region, as an application using spatie would have to do it; it is part of spatie\'s measured time.',
        'Every written file is re-read with the IntrAgile reader after the timed region and compared with the generator on the first, middle and last row and every 997th row, all 10 columns, with strict types (date as date, bool as bool, numbers as numbers). A mismatch makes the case `FAILED`.',
    ],
    'W2' => [
        '**spatie is partial**: it applies the header style (bold, fill, border) and the date format, but NOT the number formats on the two float columns and NOT the column widths, so it does less work than the other two.',
        'ours: header `Style` row, `Cell::of` with a number format on columns 2 and 8, column widths for all 10 columns. maatwebsite: `WithStyles` (header row), `WithColumnFormatting` (columns B, E, H), `WithColumnWidths`. spatie builds its per-cell OpenSpout `Row` inside the timed region, as in W1.',
        'The same strict output verification as W1 applies (the header row is checked too).',
    ],
    'W3' => [
        'The CSV forms differ per library and are verified per library: ours and maatwebsite write dates as `Y-m-d`, spatie (OpenSpout) writes `DATE_ATOM` (`2021-11-24T00:00:00+00:00`); booleans are `1`/`0` for ours and spatie and `TRUE`/`FALSE` for maatwebsite. These are the libraries\' defaults, so the files are not byte-identical.',
        'ours and spatie stream to the file; maatwebsite builds a PhpSpreadsheet sheet first.',
    ],
    'R1' => [
        'The input file is produced once by the IntrAgile writer (cell references on every cell, no `<dimension>`, inline strings). X1 checks that all three libraries read this shape with the same row count and identical values; the cached input is regenerated whenever the writer sources change.',
        'Every library iterates all rows; the checksum (row count and sum of the id column) is computed in the callback and verified, so lazily-skipped parsing would be detected.',
        'maatwebsite reads with `OnEachRow` + `WithChunkReading(1000)` (raw values, no number-format rendering, no models or database). spatie reads with `noHeaderRow()` so it returns the same number of rows.',
    ],
    'R1S' => [
        'Real Excel, Google Sheets and LibreOffice files keep their text in `xl/sharedStrings.xml`, while the R1 input (from the IntrAgile writer) uses inline strings only. The R1S input is written by `NeutralXlsxWriter` in `generate.php`, which uses none of the three libraries: plain XML streamed into a zip with a fully deduplicated shared-string table (with `count` and `uniqueCount`), a cell reference on every cell, dates as serials with the built-in date format 14 and booleans as `t="b"`. Same 10 columns and values as R1.',
        'Every library reads every row; besides the row count and id checksum, the sampled rows (first, middle, last, every 997th) are compared cell by cell with the generator in the callback (a date may come back as a date or as its serial, numbers numerically). The expected rows are prepared before the memory baseline; the comparison of about 0.1% of the rows is inside the timed region for every library alike.',
        'At 1,000,000 rows the table holds about two million unique strings, so the IntrAgile reader keeps the first 100,000 in memory and spills the rest to a temporary file (`ReadOptions::$sharedStringsInMemory`).',
    ],
    'R2' => [
        'CSV input produced by the IntrAgile writer; comma-separated, UTF-8. Delimiter and encoding are not given to any library (ours sniffs, the others use their defaults).',
        'Values are compared as strings; the checksum is the sum of the id column.',
    ],
];

$out_ = [];
$out_[] = '# Benchmark results';
$out_[] = '';
$out_[] = 'Developed and maintained by [IntrAgile Kft.](https://intragile.eu)';
$out_[] = '';

if ($smoke) {
    $out_[] = '> **SMOKE RUN: not the benchmark.** These numbers come from tiny inputs (2,000 rows) that only prove every case runs end to end. Process start-up and framework boot dominate; do not read anything into them. The full 100k / 1M matrix replaces this file.';
    $out_[] = '';
}

$out_[] = '## Machine';
$out_[] = '';
$out_[] = '- PHP: `'.sh('php -v | head -1').'`';
$out_[] = '- OS: '.(PHP_OS_FAMILY === 'Darwin' ? sh('sw_vers -productName').' '.sh('sw_vers -productVersion').' ('.sh('uname -m').')' : sh('uname -sr'));
$out_[] = '- CPU: '.(PHP_OS_FAMILY === 'Darwin' ? sh('sysctl -n machdep.cpu.brand_string').', '.sh('sysctl -n hw.ncpu').' cores' : sh("grep -m1 'model name' /proc/cpuinfo | cut -d: -f2"));
$out_[] = '- RAM: '.(PHP_OS_FAMILY === 'Darwin' ? round((int) sh('sysctl -n hw.memsize') / 1073741824).' GB' : sh("awk '/MemTotal/ {printf \"%.0f GB\", $2/1048576}' /proc/meminfo"));
$out_[] = '- Data files (var-dir): '.($storage ?? 'not recorded');
$out_[] = '- Libraries: '.implode(', ', array_map(static fn (string $name): string => "`$name` ".($versions[$name] ?? 'dev'), ['maatwebsite/excel', 'spatie/simple-excel', 'openspout/openspout', 'phpoffice/phpspreadsheet'])).', `intragile/php-spreadsheet` (path repository, working tree)';
$out_[] = '- Each case runs in a fresh `php -d memory_limit=2G` process with a hard wall-clock limit. With repeats, the library order is rotated on every repetition.';
$out_[] = '';
$out_[] = '## How to read the tables';
$out_[] = '';
$out_[] = '- **Seconds**: wall time of the operation only (`hrtime`), after the process and (for maatwebsite) the Laravel application were booted. Median over the runs, minimum in parentheses.';
$out_[] = '- **Peak MB**: `memory_get_peak_usage(true)` during the operation (the peak counter is reset after boot). **Over baseline**: the same minus `memory_get_usage(true)` right after boot, so framework overhead does not distort the comparison. **RSS MB**: `getrusage` max resident set sampled right after the timed region; it also covers native memory (zip, libxml) that PHP\'s allocator does not count.';
$out_[] = '- **Runs**: successful runs / total runs. `OOM`, `timeout` and `FAILED` are written out; nothing is omitted. `not run` means the case was not part of this run.';
$out_[] = '';

$num = static fn (?float $v, int $d = 1): string => $v === null ? 'n/a' : number_format($v, $d, '.', '');
$stat = static function (array $values, int $d = 1) use ($num): string {
    $m = median($values);

    return $m === null ? 'n/a' : $num($m, $d).(count($values) > 1 ? ' ('.$num(min($values), $d).')' : '');
};

foreach ($scenarioTitles as $id => $title) {
    $out_[] = "## $title";
    $out_[] = '';
    $sizes = array_keys($results[$id] ?? []);
    sort($sizes);
    if ($sizes === []) {
        $out_[] = '_not run_';
        $out_[] = '';
    } else {
        $out_[] = '| Rows | Library | Status | Runs | Seconds median (min) | Peak MB median (min) | Over baseline MB | RSS MB | Rows/s |';
        $out_[] = '|---:|---|---|---:|---:|---:|---:|---:|---:|';
        foreach ($sizes as $rows) {
            foreach ($libs as $key => $name) {
                if ($key === 'none' && $id[0] !== 'W') {
                    continue;
                }
                $runs = $results[$id][$rows][$key] ?? [];
                if ($runs === []) {
                    if ($key !== 'none') {
                        $out_[] = "| $rows | $name | not run | | | | | | |";
                    }

                    continue;
                }
                $good = array_values(array_filter($runs, static fn (array $r): bool => $r['ok']));
                $bad = array_values(array_filter($runs, static fn (array $r): bool => ! $r['ok']));
                $status = 'ok';
                if ($bad !== []) {
                    $error = (string) ($bad[0]['error'] ?? '');
                    $kind = str_starts_with($error, 'OOM') ? 'OOM' : (str_starts_with($error, 'timeout') ? 'timeout' : 'FAILED');
                    $status = $kind.' ('.str_replace('|', '/', substr($error, 0, $kind === 'FAILED' ? 220 : 90)).')';
                }
                $partial = ($id === 'W2' && $key === 'spatie') ? ' (partial)' : '';
                $seconds = array_map(static fn (array $r): float => (float) $r['seconds'], $good);
                $medianSeconds = median($seconds);
                $rate = ($medianSeconds !== null && $medianSeconds > 0) ? number_format($rows / $medianSeconds, 0, '.', ' ') : 'n/a';
                $out_[] = "| $rows | $name$partial | $status | ".count($good).'/'.count($runs).' | '
                    .$stat($seconds, 2).' | '
                    .$stat(array_map(static fn (array $r): float => (float) $r['peak_mb'], $good)).' | '
                    .$stat(array_map(static fn (array $r): float => (float) $r['peak_over_baseline_mb'], $good)).' | '
                    .$stat(array_map(static fn (array $r): float => (float) $r['rss_mb'], $good)).' | '.$rate.' |';
            }
        }
        $out_[] = '';
    }
    foreach ($notes[$id] as $note) {
        $out_[] = "- $note";
    }
    $out_[] = '';
}

$out_[] = '## X1 Cross-reader correctness (10,000 rows)';
$out_[] = '';
$out_[] = 'Three fixtures are written by the IntrAgile writer: a plain file and a styled file, both with null gaps in the middle of a row (and at the end of some rows), and the R1 input shape without gaps. Each library reads all three and every cell of every row is compared with the generated data (not only samples; the first row, a middle row, the last row and the cell right after a gap are included). The fixtures have no `<dimension>` element.';
$out_[] = '';
$out_[] = '| Library | Verdict | plain + gaps | styled + gaps | R1 input |';
$out_[] = '|---|---|---|---|---|';
foreach ($realLibs as $key => $name) {
    $r = isset($results['X1'][10000][$key]) ? end($results['X1'][10000][$key]) : null;
    if ($r === null) {
        $out_[] = "| $name | not run | | | |";

        continue;
    }
    $cell = static function (string $variant) use ($r): string {
        $v = $r['variants'][$variant] ?? null;

        return $v === null ? 'n/a' : $v['rows_read'].' rows, '.$v['mismatched_cells'].' mismatched cells';
    };
    $out_[] = "| $name | ".($r['ok'] ? 'ok' : 'MISMATCH').' | '.$cell('plain-gaps').' | '.$cell('styled-gaps').' | '.$cell('r1-input').' |';
}
$out_[] = '';
$anyMismatch = false;
foreach ($realLibs as $key => $name) {
    $r = isset($results['X1'][10000][$key]) ? end($results['X1'][10000][$key]) : null;
    if ($r !== null && ! $r['ok'] && ($r['mismatches'] ?? []) !== []) {
        $anyMismatch = true;
        $out_[] = "First mismatches for $name:";
        $out_[] = '';
        foreach (array_slice($r['mismatches'], 0, 6) as $m) {
            $out_[] = '- '.$m;
        }
        $out_[] = '';
    } elseif ($r !== null && ! $r['ok']) {
        $out_[] = "$name: ".($r['error'] ?? 'failed').'.';
        $out_[] = '';
    }
}
if ($anyMismatch) {
    $out_[] = 'A mismatch means that library read a file written by the IntrAgile writer differently from the generated data; this is a compatibility finding about the writer or the reader, not a benchmark artefact.';
    $out_[] = '';
}

$out_[] = '## Scenario equivalence summary';
$out_[] = '';
$out_[] = '- Same data: one deterministic generator (isolated `Random\Randomizer` with `Mt19937` seeded 20261109), identical values for every library, produced lazily.';
$out_[] = '- spatie is not booted through Laravel (it does not need it); maatwebsite is booted through Orchestra Testbench, and the boot cost is excluded from both time and the over-baseline memory figure.';
$out_[] = '- spatie W2 is partial (header style and date format only).';
$out_[] = '- Dates are real date cells in every xlsx file (see the W1 notes for how each library gets there); in CSV each library writes its own default date and boolean forms.';
$out_[] = '- The input files of R1/R2 come from the IntrAgile writer; X1 shows whether the other two libraries read that shape correctly. The R1S input comes from a neutral generator with shared strings, the layout real spreadsheet applications write.';
$out_[] = '- spatie\'s per-cell OpenSpout `Row` building for xlsx writes is inside its timed region.';
$out_[] = '- Output files are verified with strict types on sampled rows across all 10 columns; any mismatch fails the case.';
$out_[] = '';
$out_[] = 'Reproduce with the commands in [README.md](README.md).';
$out_[] = '';
$out_[] = '---';
$out_[] = '';
$out_[] = 'Developed and maintained by [IntrAgile Kft.](https://intragile.eu)';

file_put_contents($out, implode("\n", $out_)."\n");
echo "wrote $out\n";
