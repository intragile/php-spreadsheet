<?php

declare(strict_types=1);

/**
 * Deterministic benchmark data and input-file generator.
 *
 * Library usage: BenchData::rows($n) yields the same rows in every process (isolated Mt19937 seeded with 20261109).
 * CLI usage:     php generate.php <rows> [<rows>...]   builds <var-dir>/input-<rows>.{xlsx,csv}
 *                with the IntrAgile writer (the file under test is never produced by a competitor), and
 *                <var-dir>/input-<rows>-sst.xlsx with NeutralXlsxWriter (shared strings, as Excel,
 *                Google Sheets and LibreOffice write them; none of the benchmarked libraries is involved).
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

/**
 * Writes the benchmark rows as an Excel-shaped workbook without any spreadsheet library: every
 * string goes to a fully deduplicated shared-string table (with count and uniqueCount), every cell
 * has a reference, dates are serials with the built-in date format 14, booleans are t="b".
 * Sheet and string table are streamed to temporary files, so any row count takes constant memory
 * apart from the deduplication map.
 */
final class NeutralXlsxWriter
{
    private const MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PKG = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** @param iterable<int, array<int, int|float|string|bool|DateTimeInterface|null>> $rows */
    public static function write(string $path, iterable $rows): void
    {
        $sheetPath = $path.'.sheet.tmp';
        $itemsPath = $path.'.items.tmp';
        $stringsPath = $path.'.strings.tmp';
        $sheet = fopen($sheetPath, 'wb');
        $items = fopen($itemsPath, 'wb');
        /** @var array<string, int> $indexes */
        $indexes = [];
        $references = 0;
        $buffer = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="'.self::MAIN.'"><sheetData>';
        $itemBuffer = '';
        $r = 0;

        foreach ($rows as $row) {
            $r++;
            $buffer .= '<row r="'.$r.'">';
            foreach ($row as $column => $value) {
                $ref = chr(65 + $column).$r;
                if ($value === null) {
                    continue;
                }
                if (is_string($value)) {
                    $references++;
                    if (! isset($indexes[$value])) {
                        $indexes[$value] = count($indexes);
                        $itemBuffer .= '<si><t>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></si>';
                    }
                    $buffer .= '<c r="'.$ref.'" t="s"><v>'.$indexes[$value].'</v></c>';
                } elseif (is_bool($value)) {
                    $buffer .= '<c r="'.$ref.'" t="b"><v>'.($value ? '1' : '0').'</v></c>';
                } elseif ($value instanceof DateTimeInterface) {
                    $buffer .= '<c r="'.$ref.'" s="1"><v>'.var_export(BenchData::serial($value), true).'</v></c>';
                } else {
                    $buffer .= '<c r="'.$ref.'"><v>'.var_export($value, true).'</v></c>';
                }
            }
            $buffer .= '</row>';
            if (strlen($buffer) > 1048576) {
                fwrite($sheet, $buffer);
                $buffer = '';
            }
            if (strlen($itemBuffer) > 1048576) {
                fwrite($items, $itemBuffer);
                $itemBuffer = '';
            }
        }
        fwrite($sheet, $buffer.'</sheetData></worksheet>');
        fwrite($items, $itemBuffer);
        fclose($sheet);
        fclose($items);

        // count and uniqueCount are known only now, so the header goes in front of the streamed items.
        $strings = fopen($stringsPath, 'wb');
        fwrite($strings, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<sst xmlns="'.self::MAIN.'" count="'.$references.'" uniqueCount="'.count($indexes).'">');
        $source = fopen($itemsPath, 'rb');
        stream_copy_to_stream($source, $strings);
        fclose($source);
        fwrite($strings, '</sst>');
        fclose($strings);
        $indexes = [];

        @unlink($path);
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("cannot create $path");
        }
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
            .'</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::PKG.'"><Relationship Id="rId1" Type="'.self::REL.'/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="'.self::MAIN.'" xmlns:r="'.self::REL.'"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="'.self::PKG.'">'
            .'<Relationship Id="rId1" Type="'.self::REL.'/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="'.self::REL.'/styles" Target="styles.xml"/>'
            .'<Relationship Id="rId3" Type="'.self::REL.'/sharedStrings" Target="sharedStrings.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="'.self::MAIN.'"><numFmts count="0"/>'
            .'<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
        $zip->addFile($sheetPath, 'xl/worksheets/sheet1.xml');
        $zip->addFile($stringsPath, 'xl/sharedStrings.xml');
        if (! $zip->close()) {
            throw new RuntimeException("cannot write $path");
        }
        @unlink($sheetPath);
        @unlink($itemsPath);
        @unlink($stringsPath);
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

        $path = "$var/input-$rows-sst.xlsx";
        if (! BenchData::inputIsCurrent($path, $rows)) {
            @unlink("$path.meta.json");
            NeutralXlsxWriter::write($path, BenchData::rows($rows));
            file_put_contents("$path.meta.json", json_encode(['rows' => $rows, 'bytes' => filesize($path), 'fingerprint' => BenchData::fingerprint()]));
            echo "generated $path (".round(filesize($path) / 1048576, 1)." MB, shared strings)\n";
        }
    }
}
