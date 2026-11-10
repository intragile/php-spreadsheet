<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use php_user_filter;

/**
 * Stream filter that turns bare carriage returns into line feeds, so fgetcsv(), which only ends a
 * record at a line feed, can read files whose lines end with a carriage return alone. A carriage
 * return inside a quoted field becomes a line feed too, the line break of such files.
 *
 * @internal
 */
final class CsvLineEndingFilter extends php_user_filter
{
    public const FILTER = 'intragile.spreadsheet.csv-line-endings';

    /** A carriage return at the end of a chunk waits for the next one, which may start with a line feed. */
    private string $carry = '';

    public static function register(): void
    {
        if (! in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, self::class);
        }
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $data = $this->carry;
        $this->carry = '';

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $data .= $bucket->data;
        }

        if (! $closing && str_ends_with($data, "\r")) {
            $this->carry = "\r";
            $data = substr($data, 0, -1);
        }

        if ($data === '') {
            return $closing ? PSFS_PASS_ON : PSFS_FEED_ME;
        }

        if (! is_resource($this->stream)) {
            return PSFS_ERR_FATAL;
        }

        stream_bucket_append($out, stream_bucket_new($this->stream, strtr(str_replace("\r\n", "\n", $data), "\r", "\n")));

        return PSFS_PASS_ON;
    }
}
