<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal;

use IntrAgile\Spreadsheet\Exception\SpreadsheetException;

/**
 * Temporary files that are removed at shutdown even when a caller forgets or fails midway.
 *
 * @internal
 */
final class TempFile
{
    /** @var array<string, true> */
    private static array $paths = [];

    private static bool $registered = false;

    /**
     * @param  string|null  $directory  defaults to the system temp directory; pass the target's directory for same-filesystem renames
     */
    public static function create(string $prefix, ?string $directory = null): string
    {
        $directory ??= sys_get_temp_dir();

        if (! is_dir($directory) || ! is_writable($directory)) {
            throw new SpreadsheetException('Directory '.$directory.' does not exist or is not writable.');
        }

        $path = tempnam($directory, $prefix);

        if ($path === false) {
            throw new SpreadsheetException('Cannot create a temporary file in '.$directory.'.');
        }

        if (! self::$registered) {
            register_shutdown_function(static function (): void {
                foreach (array_keys(self::$paths) as $leftover) {
                    self::delete($leftover);
                }
            });
            self::$registered = true;
        }

        self::$paths[$path] = true;

        return $path;
    }

    /**
     * Moves a finished temp file onto its target atomically, with the default umask permissions
     * instead of the 0600 that tempnam() creates.
     */
    public static function publish(string $temp, string $target): void
    {
        if (! @chmod($temp, 0666 & ~umask())) {
            throw new SpreadsheetException('Cannot set permissions on '.$temp.'.');
        }

        if (! @rename($temp, $target)) {
            throw new SpreadsheetException('Cannot move the finished file to '.$target.'.');
        }

        unset(self::$paths[$temp]);
    }

    public static function delete(string $path): void
    {
        unset(self::$paths[$path]);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
