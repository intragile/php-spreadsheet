<?php

declare(strict_types=1);

/**
 * Deterministic benchmark data and input-file generator.
 *
 * Library usage: BenchData::rows($n) yields the same rows in every process.
 * CLI usage:     php generate.php <rows> [<rows>...]   builds bench/var/input-<rows>.{xlsx,csv}
 *                with the IntrAgile writer (the file under test is never produced by a competitor).
 */

use IntrAgile\Spreadsheet\Spreadsheet;

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
        mt_srand(self::SEED);

        $pool = [];
        for ($w = 0; $w < 1000; $w++) {
            $word = '';
            $length = mt_rand(4, 10);
            for ($c = 0; $c < $length; $c++) {
                $word .= chr(97 + mt_rand(0, 25));
            }
            $pool[] = $word;
        }

        for ($i = 1; $i <= $count; $i++) {
            yield $i => [
                $i,
                round(mt_rand(0, 99_999_999) / 1000, 3),
                $pool[mt_rand(0, 999)],
                sprintf('U%08d-%s', $i, dechex(mt_rand())),
                (new DateTimeImmutable('@'.(self::DATE_BASE + mt_rand(0, 2500) * 86400)))->setTimezone(new DateTimeZone('UTC')),
                mt_rand(0, 1) === 1,
                mt_rand(-1_000_000, 1_000_000),
                round(mt_rand(0, 9_999_999) / 10_000, 4),
                $pool[mt_rand(0, 999)].' '.$pool[mt_rand(0, 999)],
                'Row '.$i.' / '.$pool[mt_rand(0, 999)],
            ];
        }
    }

    /** Spreadsheet serial number of a date (UTC, midnight). */
    public static function serial(DateTimeInterface $date): float
    {
        return $date->getTimestamp() / 86400 + self::SERIAL_EPOCH_OFFSET;
    }

    /** Sum of the id column for ids 1..$count, used as a cheap content checksum. */
    public static function idSum(int $count): int
    {
        return intdiv($count * ($count + 1), 2);
    }
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    $var = __DIR__.'/var';
    is_dir($var) || mkdir($var, 0777, true);

    foreach (array_slice($argv, 1) as $arg) {
        $rows = (int) $arg;
        foreach (['xlsx', 'csv'] as $ext) {
            $path = "$var/input-$rows.$ext";
            $meta = "$path.meta.json";
            if (is_file($path) && is_file($meta) && (json_decode((string) file_get_contents($meta), true)['rows'] ?? null) === $rows) {
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

            file_put_contents($meta, json_encode(['rows' => $rows, 'bytes' => filesize($path)]));
            echo "generated $path (".round(filesize($path) / 1048576, 1)." MB)\n";
        }
    }
}
