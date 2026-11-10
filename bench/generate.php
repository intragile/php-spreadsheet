<?php

declare(strict_types=1);

/**
 * Deterministic benchmark data and input-file generator.
 *
 * Library usage: BenchData::rows($n) yields the same rows in every process (isolated Mt19937 seeded with 20261109).
 * CLI usage:     php generate.php <rows> [<rows>...]   builds <var-dir>/input-<rows>.{xlsx,csv}
 *                with the IntrAgile writer (the file under test is never produced by a competitor).
 */

use IntrAgile\Spreadsheet\Spreadsheet;
use Random\Engine\Mt19937;
use Random\Randomizer;

require __DIR__.'/vendor/autoload.php';

date_default_timezone_set('UTC');

final class BenchData
{
    public const SEED = 20261109;

    /** Unix timestamp of 2020-01-01 00:00:00 UTC, the base of the date column. */
    private const DATE_BASE = 1577836800;

    /** Days between 1899-12-30 and 1970-01-01, to convert timestamps to spreadsheet serials. */
    private const SERIAL_EPOCH_OFFSET = 25569;

    /**
     * Columns: int id, float, short string (1,000-word pool), unique string, date,
     * bool, int, float, string, string.
     *
     * @return Generator<int, array<int, int|float|string|bool|DateTimeImmutable>>
     */
    public static function rows(int $count): Generator
    {
        // Isolated generator: independent of the global mt_rand() state and identical in every process.
        $rng = new Randomizer(new Mt19937(self::SEED));

        $pool = [];
        for ($w = 0; $w < 1000; $w++) {
            $word = '';
            $length = $rng->getInt(4, 10);
            for ($c = 0; $c < $length; $c++) {
                $word .= chr(97 + $rng->getInt(0, 25));
            }
            $pool[] = $word;
        }

        for ($i = 1; $i <= $count; $i++) {
            yield $i => [
                $i,
                round($rng->getInt(0, 99_999_999) / 1000, 3),
                $pool[$rng->getInt(0, 999)],
                sprintf('U%08d-%s', $i, dechex($rng->getInt(0, 2_147_483_647))),
                (new DateTimeImmutable('@'.(self::DATE_BASE + $rng->getInt(0, 2500) * 86400)))->setTimezone(new DateTimeZone('UTC')),
                $rng->getInt(0, 1) === 1,
                $rng->getInt(-1_000_000, 1_000_000),
                round($rng->getInt(0, 9_999_999) / 10_000, 4),
                $pool[$rng->getInt(0, 999)].' '.$pool[$rng->getInt(0, 999)],
                'Row '.$i.' / '.$pool[$rng->getInt(0, 999)],
            ];
        }
    }

    /** Spreadsheet serial number of a date (UTC, midnight). */
    public static function serial(DateTimeInterface $date): float
    {
        return $date->getTimestamp() / 86400 + self::SERIAL_EPOCH_OFFSET;
    }

    /** Directory for generated files; overridable with BENCH_VAR_DIR (set by run.php --var-dir). */
    public static function varDir(): string
    {
        $dir = getenv('BENCH_VAR_DIR');

        return $dir !== false && $dir !== '' ? $dir : __DIR__.'/var';
    }

    /** Identity of the code that produces the cached input files: both writers and this generator. */
    public static function fingerprint(): string
    {
        $root = dirname(__DIR__);

        return sha1(sha1_file($root.'/src/Xlsx/XlsxWriter.php').sha1_file($root.'/src/Csv/CsvWriter.php').sha1_file(__FILE__));
    }

    /** True when $path exists and its meta file matches the row count and the current writer fingerprint. */
    public static function inputIsCurrent(string $path, int $rows): bool
    {
        $meta = json_decode((string) @file_get_contents("$path.meta.json"), true);

        return is_file($path) && is_array($meta) && ($meta['rows'] ?? null) === $rows && ($meta['fingerprint'] ?? null) === self::fingerprint();
    }

    /** Sum of the id column for ids 1..$count, used as a cheap content checksum. */
    public static function idSum(int $count): int
    {
        return intdiv($count * ($count + 1), 2);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $var = BenchData::varDir();
    is_dir($var) || mkdir($var, 0777, true);

    foreach (array_slice($argv, 1) as $arg) {
        $rows = (int) $arg;
        foreach (['xlsx', 'csv'] as $ext) {
            $path = "$var/input-$rows.$ext";
            $meta = "$path.meta.json";
            if (BenchData::inputIsCurrent($path, $rows)) {
                continue;
            }

            @unlink($meta);
            $writer = Spreadsheet::write($path);
            $writer->addRows(BenchData::rows($rows));
            $writer->close();

            // Validate with our own reader before declaring the input usable.
            $reader = Spreadsheet::read($path);
            $count = 0;
            $idSum = 0;
            foreach ($reader->rows() as $row) {
                $count++;
                $idSum += (int) $row[0];
            }
            $reader->close();

            if ($count !== $rows || $idSum !== BenchData::idSum($rows)) {
                fwrite(STDERR, "input $path invalid: $count rows, id sum $idSum\n");
                exit(1);
            }

            file_put_contents($meta, json_encode(['rows' => $rows, 'bytes' => filesize($path), 'fingerprint' => BenchData::fingerprint()]));
            echo "generated $path (".round(filesize($path) / 1048576, 1)." MB)\n";
        }
    }
}
