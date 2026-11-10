<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Builds throw-away input files for tests and removes them afterwards.
 */
final class Fixtures
{
    /** @var list<string> */
    private static array $paths = [];

    public static function file(string $contents, string $extension = 'bin'): string
    {
        $path = self::path($extension);
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param  array<string, string>  $entries  entry name => contents
     */
    public static function zip(array $entries, string $extension = 'zip'): string
    {
        $path = self::path($extension);
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create fixture zip.');
        }

        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }

        $zip->close();

        return $path;
    }

    /**
     * A zip whose headers understate the real size of one entry, as a zip bomb would.
     */
    public static function zipWithFalseSize(string $name, string $contents, int $declaredSize): string
    {
        $path = self::zip([$name => $contents]);
        $bytes = (string) file_get_contents($path);

        $central = strpos($bytes, "PK\x01\x02");
        $local = strpos($bytes, "PK\x03\x04");

        if ($central === false || $local === false) {
            throw new RuntimeException('Unexpected zip layout.');
        }

        $bytes = substr_replace($bytes, pack('V', $declaredSize), $central + 24, 4);
        $bytes = substr_replace($bytes, pack('V', $declaredSize), $local + 22, 4);
        file_put_contents($path, $bytes);

        return $path;
    }

    /**
     * Path of a committed fixture under tests/fixtures.
     */
    public static function committed(string $relative): string
    {
        return dirname(__DIR__).'/fixtures/'.$relative;
    }

    public static function path(string $extension = 'bin'): string
    {
        $base = tempnam(sys_get_temp_dir(), 'sps_fx_');

        if ($base === false) {
            throw new RuntimeException('Cannot create a temporary fixture file.');
        }

        unlink($base);
        $path = $base.'.'.$extension;
        self::$paths[] = $path;

        return $path;
    }

    public static function cleanup(): void
    {
        foreach (self::$paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }

        self::$paths = [];
    }
}
