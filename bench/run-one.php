<?php

declare(strict_types=1);

/**
 * Runs exactly one benchmark case in this (fresh) process and prints one JSON line.
 *
 * Usage: php -d memory_limit=2G run-one.php <ours|spatie|maatwebsite> <W1|W2|W3|R1|R2|X1> <rows> [--keep]
 */

use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use IntrAgile\Spreadsheet\Style;
use Maatwebsite\Excel\Concerns\FromGenerator;
use Maatwebsite\Excel\Concerns\OnEachRow;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithCustomChunkSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Excel;
use Maatwebsite\Excel\ExcelServiceProvider;
use Maatwebsite\Excel\Row as MwRow;
use OpenSpout\Common\Entity\Style\Border;
use OpenSpout\Common\Entity\Style\BorderName;
use OpenSpout\Common\Entity\Style\BorderPart;
use OpenSpout\Common\Entity\Style\BorderStyle;
use OpenSpout\Common\Entity\Style\BorderWidth;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style as SpoutStyle;
use Orchestra\Testbench\Foundation\Application;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spatie\SimpleExcel\SimpleExcelWriter;

require __DIR__.'/generate.php';

const VAR_DIR = __DIR__.'/var';
const HEADER = ['id', 'amount', 'word', 'unique', 'date', 'flag', 'qty', 'ratio', 'text', 'note'];

// ---------------------------------------------------------------------------------------------
// Maatwebsite concerns (Laravel Excel 4, FromGenerator + custom chunk size / chunked OnEachRow)
// ---------------------------------------------------------------------------------------------

final class MwExport implements FromGenerator, WithColumnFormatting, WithCustomChunkSize
{
    public function __construct(
        private readonly int $rows,
        private readonly bool $csv,
        private readonly bool $header,
    ) {}

    public function generator(): Generator
    {
        if ($this->header) {
            yield HEADER;
        }

        foreach (BenchData::rows($this->rows) as $row) {
            // Laravel Excel has no native date cell: xlsx gets a serial plus a date format
            // (below), csv gets the formatted text, like the other libraries write it.
            $row[4] = $this->csv ? $row[4]->format('Y-m-d') : BenchData::serial($row[4]);
            yield $row;
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function columnFormats(): array
    {
        return $this->csv ? [] : ['E' => NumberFormat::FORMAT_DATE_YYYYMMDD];
    }
}

final class MwStyledExport implements FromGenerator, WithColumnFormatting, WithColumnWidths, WithCustomChunkSize, WithStyles
{
    public function __construct(private readonly int $rows) {}

    public function generator(): Generator
    {
        yield HEADER;

        foreach (BenchData::rows($this->rows) as $row) {
            $row[4] = BenchData::serial($row[4]);
            yield $row;
        }
    }

    public function chunkSize(): int
    {
        return 1000;
    }

    public function columnFormats(): array
    {
        return [
            'B' => '#,##0.00',
            'E' => NumberFormat::FORMAT_DATE_YYYYMMDD,
            'H' => '0.0000',
        ];
    }

    public function columnWidths(): array
    {
        return array_combine(range('A', 'J'), array_fill(0, 10, 14.0));
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => [
                'font' => ['bold' => true],
                'fill' => ['fillType' => 'solid', 'startColor' => ['rgb' => 'DDDDDD']],
                'borders' => ['allBorders' => ['borderStyle' => 'thin', 'color' => ['rgb' => '000000']]],
            ],
        ];
    }
}

final class MwImport implements OnEachRow, WithChunkReading
{
    /** @param Closure(array<int, mixed>): void $callback */
    public function __construct(private readonly Closure $callback) {}

    public function onRow(MwRow $row): void
    {
        // Raw cell values (no number-format rendering), like the other readers return.
        ($this->callback)($row->toArray(null, false, false));
    }

    public function chunkSize(): int
    {
        return 1000;
    }
}

// ---------------------------------------------------------------------------------------------
// Library adapters
// ---------------------------------------------------------------------------------------------

function bootLaravel(): void
{
    static $booted = false;
    if ($booted) {
        return;
    }
    $booted = true;

    foreach (['/tmp-mw', '/laravel/bootstrap/cache', '/laravel/storage/framework/views', '/laravel/storage/framework/cache'] as $dir) {
        is_dir(VAR_DIR.$dir) || mkdir(VAR_DIR.$dir, 0777, true);
    }

    $app = Application::create(
        basePath: VAR_DIR.'/laravel',
        options: ['extra' => ['providers' => [ExcelServiceProvider::class]]],
    );
    $app['config']->set('filesystems.disks.bench', ['driver' => 'local', 'root' => VAR_DIR]);
    $app['config']->set('excel.temporary_files.local_path', VAR_DIR.'/tmp-mw');
    // Resolve the service now so the framework boot cost is part of the baseline.
    $app->make(Excel::class);
}

/** Writes $rows rows of benchmark data into $path. */
function produceFile(string $lib, string $scenario, string $path, int $rows): void
{
    $ext = pathinfo($path, PATHINFO_EXTENSION);

    if ($lib === 'ours') {
        $writer = Spreadsheet::write($path);
        if ($scenario === 'W2') {
            $header = Style::make()->bold()->fill('DDDDDD')->border();
            $money = Style::make()->numberFormat('#,##0.00');
            $ratio = Style::make()->numberFormat('0.0000');
            $writer->sheet('Data', new SheetOptions(columnWidths: array_fill(1, 10, 14.0)));
            $writer->addRow(HEADER, $header);
            foreach (BenchData::rows($rows) as $row) {
                $row[1] = Cell::of($row[1], $money);
                $row[7] = Cell::of($row[7], $ratio);
                $writer->addRow($row);
            }
        } else {
            $writer->addRows(BenchData::rows($rows));
        }
        $writer->close();

        return;
    }

    if ($lib === 'spatie') {
        $writer = SimpleExcelWriter::create($path)->noHeaderRow();
        if ($scenario === 'W2') {
            // Partial: header style only; no number formats, no column widths.
            $border = new Border(...array_map(
                static fn (BorderName $side): BorderPart => new BorderPart($side, Color::BLACK, BorderWidth::THIN, BorderStyle::SOLID),
                BorderName::cases(),
            ));
            $writer->setHeaderStyle((new SpoutStyle)->withFontBold(true)->withBackgroundColor('DDDDDD')->withBorder($border));
            $writer->addHeader(HEADER);
        }
        $writer->addRows(BenchData::rows($rows));
        $writer->close();

        return;
    }

    bootLaravel();
    $export = $scenario === 'W2'
        ? new MwStyledExport($rows)
        : new MwExport($rows, $ext === 'csv', false);
    app(Excel::class)->store($export, basename($path), 'bench');
}

/**
 * Streams every row of $path through $callback(list<mixed> $row) with the given library.
 *
 * @param  Closure(array<int, mixed>): void  $callback
 */
function streamRows(string $lib, string $path, Closure $callback): void
{
    if ($lib === 'ours') {
        $reader = Spreadsheet::read($path);
        foreach ($reader->rows() as $row) {
            $callback($row);
        }
        $reader->close();

        return;
    }

    if ($lib === 'spatie') {
        $reader = SimpleExcelReader::create($path)->noHeaderRow();
        foreach ($reader->getRows() as $row) {
            $callback(array_values($row));
        }
        $reader->close();

        return;
    }

    bootLaravel();
    app(Excel::class)->import(new MwImport($callback), basename($path), 'bench');
}

// ---------------------------------------------------------------------------------------------
// Verification helpers
// ---------------------------------------------------------------------------------------------

/** Row count and id checksum of a file, read with the IntrAgile reader (never the library under test). */
function inspect(string $path, bool $skipFirst): array
{
    $reader = Spreadsheet::read($path);
    $count = 0;
    $sum = 0;
    foreach ($reader->rows() as $row) {
        if ($skipFirst && $count === 0) {
            $count++;

            continue;
        }
        $count++;
        $sum += (int) $row[0];
    }
    $reader->close();

    return [$count, $sum];
}

/** Compares an actual cell against the generated value; returns null when equal, else a description. */
function cellMismatch(mixed $expected, mixed $actual): ?string
{
    if ($actual === '') {
        $actual = null; // empty cells: OpenSpout reports '' where others report null
    }

    $ok = match (true) {
        $expected === null => $actual === null,
        is_bool($expected) => $actual === $expected,
        $expected instanceof DateTimeInterface => ($actual instanceof DateTimeInterface && $actual->format('Y-m-d') === $expected->format('Y-m-d'))
            || ((is_int($actual) || is_float($actual)) && abs($actual - BenchData::serial($expected)) < 1e-6),
        is_int($expected), is_float($expected) => (is_int($actual) || is_float($actual)) && abs($actual - $expected) <= 1e-9 * max(1.0, abs($expected)),
        default => $actual === $expected,
    };

    if ($ok) {
        return null;
    }

    $show = static fn (mixed $v): string => $v instanceof DateTimeInterface ? 'date '.$v->format('Y-m-d H:i:s') : get_debug_type($v).' '.var_export($v, true);

    return 'expected '.$show($expected).', got '.$show($actual);
}

/** Expected rows of the X1 files: generator data with deterministic null gaps mid-row and at the row end. */
function x1Rows(int $count, bool $gaps): Generator
{
    foreach (BenchData::rows($count) as $i => $row) {
        if ($gaps) {
            if ($i % 3 === 0) {
                $row[2] = null;
                $row[3] = null;
            }
            if ($i % 5 === 0) {
                $row[6] = null;
            }
            if ($i % 7 === 0) {
                $row[9] = null;
            }
        }
        yield $i => $row;
    }
}

function x1(string $lib, int $count): array
{
    $variants = [
        'plain-gaps' => [VAR_DIR.'/x1-plain.xlsx', true, false],
        'styled-gaps' => [VAR_DIR.'/x1-styled.xlsx', true, true],
        'r1-input' => [VAR_DIR.'/x1-r1input.xlsx', false, false],
    ];
    $mismatches = [];
    $checked = [];

    foreach ($variants as $name => [$path, $gaps, $styled]) {
        // Fixture is always produced by our writer, whichever library is being checked.
        $writer = Spreadsheet::write($path);
        $bold = Style::make()->bold()->fill('FFFF00');
        $fmt = Style::make()->numberFormat('#,##0.00');
        foreach (x1Rows($count, $gaps) as $i => $row) {
            if ($styled) {
                if ($row[1] !== null) {
                    $row[1] = Cell::of($row[1], $fmt);
                }
                $writer->addRow($row, $i % 10 === 0 ? $bold : null);
            } else {
                $writer->addRow($row);
            }
        }
        $writer->close();

        $expected = x1Rows($count, $gaps);
        $n = 0;
        $variantMismatches = 0;
        streamRows($lib, $path, function (array $actual) use (&$n, $expected, $name, &$mismatches, &$variantMismatches): void {
            $n++;
            $want = $expected->current();
            $expected->next();
            if ($want === null) {
                $variantMismatches++;
                $mismatches[] = "$name: unexpected extra row $n";

                return;
            }
            $actual = array_pad($actual, 10, null);
            for ($c = 0; $c < 10; $c++) {
                $diff = cellMismatch($want[$c], $actual[$c] ?? null);
                if ($diff !== null) {
                    $variantMismatches++;
                    if (count($mismatches) < 20) {
                        $mismatches[] = "$name: row $n col ".($c + 1).": $diff";
                    }
                }
            }
        });
        if ($n !== $count) {
            $variantMismatches++;
            $mismatches[] = "$name: row count $n, expected $count";
        }
        $checked[$name] = ['rows_read' => $n, 'mismatched_cells' => $variantMismatches];
    }

    return ['ok' => $mismatches === [], 'mismatches' => $mismatches, 'variants' => $checked];
}

// ---------------------------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------------------------

[, $lib, $scenario, $rowsArg] = $argv + [null, null, null, null];
$keep = in_array('--keep', $argv, true);

if (! in_array($lib, ['ours', 'spatie', 'maatwebsite'], true) || ! in_array($scenario, ['W1', 'W2', 'W3', 'R1', 'R2', 'X1'], true) || ! ctype_digit((string) $rowsArg)) {
    fwrite(STDERR, "usage: run-one.php <ours|spatie|maatwebsite> <W1|W2|W3|R1|R2|X1> <rows>\n");
    exit(2);
}
$rows = (int) $rowsArg;

$result = ['lib' => $lib, 'scenario' => $scenario, 'rows' => $rows, 'ok' => false, 'error' => null];
$emitted = false;
$reserve = str_repeat('x', 262144); // freed in the shutdown handler so a fatal OOM can still report

$emit = static function () use (&$result, &$emitted): void {
    if ($emitted) {
        return;
    }
    $emitted = true;
    echo "\n".json_encode($result, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
};

register_shutdown_function(function () use (&$result, $emit, &$reserve, &$baseline): void {
    $reserve = null;
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        $result['ok'] = false;
        $result['error'] = str_contains($error['message'], 'Allowed memory size') ? 'OOM: '.substr($error['message'], 0, 120) : 'fatal: '.substr($error['message'], 0, 200);
        $result['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
    }
    $emit();
});

$outPath = null;

try {
    $ext = match ($scenario) {
        'W3', 'R2' => 'csv',
        default => 'xlsx',
    };

    if ($scenario === 'X1') {
        $result['rows'] = $rows = 10_000;
    }

    if ($lib === 'maatwebsite') {
        bootLaravel(); // boot (and the extra autoloading it triggers) is not part of the measurement
    }

    $baseline = memory_get_usage(true);
    $result['baseline_mb'] = round($baseline / 1048576, 1);

    if ($scenario === 'X1') {
        $start = hrtime(true);
        $out = x1($lib, $rows);
        $seconds = (hrtime(true) - $start) / 1e9;
        $result = array_merge($result, $out);
        $result['seconds'] = round($seconds, 3);
    } elseif ($scenario[0] === 'W') {
        $outPath = VAR_DIR."/out-$lib-$scenario-$rows.$ext";
        @unlink($outPath);

        $start = hrtime(true);
        produceFile($lib, $scenario, $outPath, $rows);
        $seconds = (hrtime(true) - $start) / 1e9;
        $peak = memory_get_peak_usage(true);

        $result['seconds'] = round($seconds, 3);
        $result['file_mb'] = round(filesize($outPath) / 1048576, 1);
        $result['peak_mb'] = round($peak / 1048576, 1);

        // Verify with the IntrAgile reader after the measurement.
        $header = $scenario === 'W2';
        [$count, $sum] = inspect($outPath, $header);
        $wantCount = $rows + ($header ? 1 : 0);
        if ($count !== $wantCount || $sum !== BenchData::idSum($rows)) {
            throw new RuntimeException("verification failed: $count rows (expected $wantCount), id sum $sum (expected ".BenchData::idSum($rows).')');
        }
        $result['ok'] = true;
    } else {
        $input = VAR_DIR."/input-$rows.$ext";
        if (! is_file($input)) {
            throw new RuntimeException("input file missing: $input (run generate.php $rows)");
        }
        $count = 0;
        $sum = 0;

        $start = hrtime(true);
        streamRows($lib, $input, function (array $row) use (&$count, &$sum): void {
            $count++;
            $sum += (int) ($row[0] ?? 0);
        });
        $seconds = (hrtime(true) - $start) / 1e9;

        $result['seconds'] = round($seconds, 3);
        $result['peak_mb'] = round(memory_get_peak_usage(true) / 1048576, 1);
        if ($count !== $rows || $sum !== BenchData::idSum($rows)) {
            throw new RuntimeException("read $count rows (expected $rows), id sum $sum (expected ".BenchData::idSum($rows).')');
        }
        $result['ok'] = true;
    }
} catch (Throwable $e) {
    $result['ok'] = false;
    $result['error'] = get_class($e).': '.substr($e->getMessage(), 0, 300);
    $result['peak_mb'] ??= round(memory_get_peak_usage(true) / 1048576, 1);
} finally {
    if ($outPath !== null && ! $keep) {
        @unlink($outPath);
    }
}

$result['peak_mb'] ??= round(memory_get_peak_usage(true) / 1048576, 1);
$result['peak_over_baseline_mb'] = round(max(0, ($result['peak_mb'] * 1048576) - $baseline) / 1048576, 1);
$usage = getrusage();
// ru_maxrss is bytes on macOS and kilobytes on Linux.
$result['rss_mb'] = round($usage['ru_maxrss'] / (PHP_OS_FAMILY === 'Darwin' ? 1048576 : 1024), 1);
$emit();
exit($result['ok'] ? 0 : 1);
