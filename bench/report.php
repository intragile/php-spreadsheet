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

/** Latest result per library, scenario and row count. */
$results = [];
foreach (file($in, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
    $r = json_decode($line, true);
    if (is_array($r)) {
        $results[$r['scenario']][$r['rows']][$r['lib']] = $r;
    }
}

function sh(string $cmd): string
{
    return trim((string) shell_exec($cmd.' 2>/dev/null'));
}

$libs = ['ours' => 'intragile/php-spreadsheet', 'spatie' => 'spatie/simple-excel', 'maatwebsite' => 'maatwebsite/excel'];
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
    'R2' => 'R2 Read CSV (input produced by the IntrAgile writer)',
];

$notes = [
    'W1' => [
        'All libraries write the same 10 columns from the same lazy generator (the generator cost is identical for everyone).',
        'maatwebsite has no date cell type: the date column is written as a serial number plus a `yyyy-mm-dd` column format (`WithColumnFormatting`), which the other two libraries do natively. That is extra work for maatwebsite that is necessary to produce an equivalent file.',
        'maatwebsite goes through `FromGenerator` + `WithCustomChunkSize(1000)`; PhpSpreadsheet still keeps all cells of the sheet in memory until the file is saved.',
        'spatie/OpenSpout writes list rows with `noHeaderRow()`.',
    ],
    'W2' => [
        '**spatie is partial**: it applies the header style (bold, fill, border) but NOT the number formats on 2 columns and NOT the column widths, so it does less work than the other two.',
        'ours: header `Style` row, `Cell::of` with a number format on columns 2 and 8, column widths for all 10 columns. maatwebsite: `WithStyles` (header row), `WithColumnFormatting` (columns B, E, H), `WithColumnWidths`.',
        'Both numeric formats and the date format are applied through the library API a normal user would use.',
    ],
    'W3' => [
        'maatwebsite writes the date as text `Y-m-d` (the others format dates themselves); booleans are written in each library\'s own CSV representation.',
        'spatie/OpenSpout and ours stream to the file; maatwebsite builds a PhpSpreadsheet sheet first.',
    ],
    'R1' => [
        'The input file is produced once by the IntrAgile writer (cell references on every cell, no `<dimension>`, inline strings). This favours no library on parsing speed, but see X1: it was checked that all three libraries read it with the same row count and identical values.',
        'Every library iterates all rows; the checksum (row count and sum of the id column) is computed in the callback and verified, so lazily-skipped parsing would be detected.',
        'maatwebsite reads with `OnEachRow` + `WithChunkReading(1000)` (raw values, no number-format rendering, no models or database). spatie reads with `noHeaderRow()` so it returns the same number of rows.',
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
$out_[] = '- Libraries: '.implode(', ', array_map(static fn (string $name): string => "`$name` ".($versions[$name] ?? 'dev'), ['maatwebsite/excel', 'spatie/simple-excel', 'openspout/openspout', 'phpoffice/phpspreadsheet'])).', `intragile/php-spreadsheet` (path repository, working tree)';
$out_[] = '- Each case runs in a fresh `php -d memory_limit=2G` process with a hard wall-clock limit; the file is verified afterwards with the IntrAgile reader (row count and id checksum) and a mismatch marks the case as failed.';
$out_[] = '';
$out_[] = '## How to read the tables';
$out_[] = '';
$out_[] = '- **Seconds**: wall time of the operation only (`hrtime`), after the process and (for maatwebsite) the Laravel application were booted.';
$out_[] = '- **Peak MB**: `memory_get_peak_usage(true)` of the whole process. **Over baseline**: the same minus `memory_get_usage(true)` right after boot, so framework overhead does not distort the comparison. **RSS MB**: `getrusage` max resident set, which also covers native memory (zip, libxml) that PHP\'s allocator does not count.';
$out_[] = '- `OOM`, `timeout` and `FAILED` are written out; nothing is omitted. `not run` means the case was not part of this run.';
$out_[] = '';

$fmt = static fn (mixed $v, int $d = 1): string => $v === null ? 'n/a' : number_format((float) $v, $d, '.', '');

foreach ($scenarioTitles as $id => $title) {
    $out_[] = "## $title";
    $out_[] = '';
    $sizes = array_keys($results[$id] ?? []);
    sort($sizes);
    if ($sizes === []) {
        $out_[] = '_not run_';
        $out_[] = '';
    } else {
        $out_[] = '| Rows | Library | Status | Seconds | Peak MB | Over baseline MB | RSS MB | Rows/s |';
        $out_[] = '|---:|---|---|---:|---:|---:|---:|---:|';
        foreach ($sizes as $rows) {
            foreach ($libs as $key => $name) {
                $r = $results[$id][$rows][$key] ?? null;
                if ($r === null) {
                    $out_[] = "| $rows | $name | not run | | | | | |";

                    continue;
                }
                $error = (string) ($r['error'] ?? '');
                $status = $r['ok'] ? 'ok' : (str_starts_with($error, 'OOM') ? 'OOM' : (str_starts_with($error, 'timeout') ? 'timeout' : 'FAILED'));
                if (! $r['ok'] && $status === 'FAILED') {
                    $status .= ': '.str_replace('|', '/', substr($error, 0, 140));
                } elseif (! $r['ok']) {
                    $status .= ' ('.str_replace('|', '/', substr($error, 0, 90)).')';
                }
                $partial = ($id === 'W2' && $key === 'spatie') ? ' (partial)' : '';
                $rate = ($r['ok'] && ($r['seconds'] ?? 0) > 0) ? number_format($rows / $r['seconds'], 0, '.', ' ') : 'n/a';
                $out_[] = "| $rows | $name$partial | $status | ".$fmt($r['seconds'], 2).' | '.$fmt($r['peak_mb'] ?? null).' | '.$fmt($r['peak_over_baseline_mb'] ?? null).' | '.$fmt($r['rss_mb'] ?? null).' | '.$rate.' |';
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
foreach ($libs as $key => $name) {
    $r = $results['X1'][10000][$key] ?? null;
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
foreach ($libs as $key => $name) {
    $r = $results['X1'][10000][$key] ?? null;
    if ($r !== null && ! $r['ok'] && ($r['mismatches'] ?? []) !== []) {
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

if (($results['X1'][10000]['maatwebsite']['ok'] ?? true) === false && ($results['X1'][10000]['maatwebsite']['mismatches'] ?? []) !== []) {
    $out_[] = 'Diagnosis (inspected by hand in the fixture XML and in PhpSpreadsheet\'s `Reader\\Xlsx::loadSheetData`): the IntrAgile writer puts an `r` attribute only on the first cell after a gap and none on the cells that follow it. The spec allows that, and the IntrAgile reader and OpenSpout resynchronise on the explicit `r`, but PhpSpreadsheet (the engine behind maatwebsite/excel) counts cells without `r` positionally and does not resynchronise, so every cell after a gap lands in the wrong column. Files without gaps are read correctly by all three. This is a compatibility defect of the writer, not a benchmark artefact.';
    $out_[] = '';
}

$out_[] = '## Scenario equivalence summary';
$out_[] = '';
$out_[] = '- Same data: one deterministic generator (`mt_srand(20261109)`), identical values for every library, produced lazily.';
$out_[] = '- spatie is not booted through Laravel (it does not need it); maatwebsite is booted through Orchestra Testbench, and the boot cost is excluded from both time and the over-baseline memory figure.';
$out_[] = '- spatie W2 is partial (header style only).';
$out_[] = '- maatwebsite writes dates as serial + format in xlsx and as text in csv; the other libraries write native dates.';
$out_[] = '- The input files of R1/R2 come from the IntrAgile writer; X1 shows whether the other two libraries read that shape correctly.';
$out_[] = '';
$out_[] = 'Reproduce with the commands in [README.md](README.md).';
$out_[] = '';
$out_[] = '---';
$out_[] = '';
$out_[] = 'Developed and maintained by [IntrAgile Kft.](https://intragile.eu)';

file_put_contents($out, implode("\n", $out_)."\n");
echo "wrote $out\n";
