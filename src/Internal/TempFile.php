<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal;

use RuntimeException;

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
        $path = @tempnam($directory, $prefix);

        if ($path === false) {
            throw new RuntimeException('Cannot create a temporary file in '.$directory.'.');
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

    public static function delete(string $path): void
    {
        unset(self::$paths[$path]);

        if (is_file($path)) {
            @unlink($path);
        }
    }
}
