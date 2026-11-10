<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use php_user_filter;

/**
 * Stream filter that decodes CSV input to UTF-8 through CsvDecoder.
 *
 * @internal
 */
final class CsvDecodeFilter extends php_user_filter
{
    /** @var array<string, string>|null */
    private ?array $table = null;

    private string $encoding = '';

    private int $unit = 1;

    private string $carry = '';

    public function onCreate(): bool
    {
        if (! is_string($this->params)) {
            return false;
        }

        $plan = CsvDecoder::plan($this->params);
        $this->table = $plan['table'];
        $this->encoding = (string) $plan['encoding'];
        $this->unit = $plan['unit'];

        return true;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        $data = $this->carry;

        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;
            $data .= $bucket->data;
        }

        if ($this->table !== null) {
            $decoded = strtr($data, $this->table);
            $this->carry = '';
        } else {
            $tail = $closing ? 0 : CsvDecoder::incompleteTail($data, $this->encoding, $this->unit);
            $this->carry = $tail > 0 ? substr($data, -$tail) : '';
            $decoded = CsvDecoder::decodeUnits($tail > 0 ? substr($data, 0, -$tail) : $data, $this->encoding);
        }

        if ($decoded === '') {
            return $closing ? PSFS_PASS_ON : PSFS_FEED_ME;
        }

        if (! is_resource($this->stream)) {
            return PSFS_ERR_FATAL;
        }

        stream_bucket_append($out, stream_bucket_new($this->stream, $decoded));

        return PSFS_PASS_ON;
    }
}
