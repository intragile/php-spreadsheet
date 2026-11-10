<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use php_user_filter;

/**
 * Stream filter that stops CSV input whose fields or records outgrow the read limits before
 * fgetcsv() buffers them: a stray quote would otherwise turn the rest of the file into one field.
 *
 * It follows the record structure the way fgetcsv() does: an enclosure opens a quoted field only
 * at the start of a field (after optional spaces and tabs), a doubled enclosure inside a quoted
 * field is literal, and only a line feed outside quotes ends a record. Field sizes are counted in
 * raw bytes including enclosures. Regions without an enclosure are handled a line at a time.
 *
 * @internal
 */
final class CsvGuardFilter extends php_user_filter
{
    public const FILTER = 'intragile.spreadsheet.csv-guard';

    private const FIELD_START = 0;

    private const UNQUOTED = 1;

    private const QUOTED = 2;

    /** Just read an enclosure inside a quoted field: either an escaped enclosure or the end of the quotes follows. */
    private const QUOTE_SEEN = 3;

    /** @var non-empty-string */
    private string $delimiter = ',';

    private string $enclosure = '"';

    private string $blanks = " \t";

    private int $maxFieldBytes = PHP_INT_MAX;

    private int $maxFields = PHP_INT_MAX;

    private int $state = self::FIELD_START;

    private int $fieldBytes = 0;

    /** Delimiters seen in the current record. */
    private int $delimiters = 0;

    private bool $failed = false;

    /** Matches a run of complete, well-formed records from a record start. */
    private string $records = '';

    public static function register(): void
    {
        if (! in_array(self::FILTER, stream_get_filters(), true)) {
            stream_filter_register(self::FILTER, self::class);
        }
    }

    public function onCreate(): bool
    {
        if (! is_array($this->params)) {
            return false;
        }

        /** @var array{delimiter: non-empty-string, enclosure: string, maxFieldBytes: int, maxFields: int} $params */
        $params = $this->params;
        $this->delimiter = $params['delimiter'];
        $this->enclosure = $params['enclosure'];
        $this->maxFieldBytes = $params['maxFieldBytes'];
        $this->maxFields = $params['maxFields'];
        $this->blanks = str_replace($this->delimiter, '', " \t");

        $d = preg_quote($this->delimiter, '/');
        $e = preg_quote($this->enclosure, '/');
        $b = $this->blanks === '' ? '' : '['.preg_quote($this->blanks, '/').']*+';
        // A field: optional blanks, then a quoted part followed by text up to the delimiter, or
        // unquoted text that does not start with the enclosure, or nothing.
        $field = $b.'(?:'.$e.'(?:[^'.$e.']++|'.$e.$e.')*+'.$e.'[^'.$d.'\n]*+|[^'.$e.$d.'\n][^'.$d.'\n]*+)?+';
        $this->records = '/\G(?:'.$field.'(?:'.$d.$field.')*+\n)*+/';

        return true;
    }

    /**
     * @param  resource  $in
     * @param  resource  $out
     * @param  int  $consumed
     *
     * @throws LimitExceeded when a field or record outgrows the limits
     */
    public function filter($in, $out, &$consumed, bool $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $consumed += $bucket->datalen;

            if ($this->failed) {
                continue;
            }

            try {
                $this->scan($bucket->data);
            } catch (LimitExceeded $exception) {
                $this->failed = true;

                throw $exception;
            }

            stream_bucket_append($out, $bucket);
        }

        return PSFS_PASS_ON;
    }

    private function scan(string $data): void
    {
        $length = strlen($data);
        $lastEnclosure = strrpos($data, $this->enclosure);
        $lastEnclosure = $lastEnclosure === false ? -1 : $lastEnclosure;
        $position = 0;

        while ($position < $length) {
            if ($this->state === self::FIELD_START && $this->fieldBytes === 0 && $this->delimiters === 0) {
                $position = $this->skipRecords($data, $position);

                if ($position >= $length) {
                    return;
                }
            }

            if ($position > $lastEnclosure && ($this->state === self::UNQUOTED || $this->state === self::FIELD_START)) {
                $this->scanPlain($position === 0 ? $data : substr($data, $position));

                return;
            }

            switch ($this->state) {
                case self::FIELD_START:
                    $blank = $this->blanks === '' ? 0 : strspn($data, $this->blanks, $position);
                    $this->grow($blank);
                    $position += $blank;

                    if ($position < $length) {
                        if ($data[$position] === $this->enclosure) {
                            $this->state = self::QUOTED;
                            $this->grow(1);
                            $position++;
                        } else {
                            $this->state = self::UNQUOTED;
                        }
                    }

                    break;
                case self::UNQUOTED:
                    $run = strcspn($data, $this->delimiter."\n", $position);
                    $this->grow($run);
                    $position += $run;

                    if ($position < $length) {
                        $this->boundary($data[$position] === "\n");
                        $position++;
                    }

                    break;
                case self::QUOTED:
                    $close = strpos($data, $this->enclosure, $position);

                    if ($close === false) {
                        $this->grow($length - $position);
                        $position = $length;
                    } else {
                        $this->grow($close - $position + 1);
                        $position = $close + 1;
                        $this->state = self::QUOTE_SEEN;
                    }

                    break;
                default:
                    if ($data[$position] === $this->enclosure) {
                        $this->grow(1);
                        $position++;
                        $this->state = self::QUOTED;
                    } else {
                        $this->state = self::UNQUOTED;
                    }
            }
        }
    }

    /**
     * Skips the complete records at a record start in one regular-expression match, as long as
     * none of them can break a limit: a run no longer than the field limit holds no longer field,
     * and a run with fewer delimiters than the field cap holds no record with too many fields.
     * Returns where the slower scan has to continue.
     */
    private function skipRecords(string $data, int $position): int
    {
        if (preg_match($this->records, $data, $match, 0, $position) !== 1 || $match[0] === '') {
            return $position;
        }

        $run = strlen($match[0]);

        if ($run > $this->maxFieldBytes || substr_count($match[0], $this->delimiter) >= $this->maxFields) {
            return $position;
        }

        return $position + $run;
    }

    /**
     * Scans text without enclosures, starting outside quotes.
     */
    private function scanPlain(string $text): void
    {
        $lines = explode("\n", $text);
        $last = count($lines) - 1;

        foreach ($lines as $index => $line) {
            $lineLength = strlen($line);
            $delimiters = substr_count($line, $this->delimiter);

            if ($this->delimiters + $delimiters >= $this->maxFields) {
                throw $this->tooManyFields();
            }

            if ($this->fieldBytes + $lineLength > $this->maxFieldBytes) {
                // Rare: only now is it worth finding the longest field of the line.
                $carried = $this->fieldBytes;

                foreach (explode($this->delimiter, $line) as $field) {
                    if ($carried + strlen($field) > $this->maxFieldBytes) {
                        throw $this->fieldTooLong();
                    }

                    $carried = 0;
                }
            }

            if ($index < $last) {
                $this->delimiters = 0;
                $this->fieldBytes = 0;
                $this->state = self::FIELD_START;

                continue;
            }

            $this->delimiters += $delimiters;
            $lastDelimiter = $delimiters > 0 ? strrpos($line, $this->delimiter) : false;
            $tail = $lastDelimiter === false ? $line : substr($line, $lastDelimiter + 1);
            $this->fieldBytes = $lastDelimiter === false ? $this->fieldBytes + $lineLength : strlen($tail);

            // A field holding only blanks so far may still open quotes.
            if (($lastDelimiter !== false || $this->state === self::FIELD_START) && strspn($tail, $this->blanks) === strlen($tail)) {
                $this->state = self::FIELD_START;
            } else {
                $this->state = self::UNQUOTED;
            }
        }
    }

    private function grow(int $bytes): void
    {
        $this->fieldBytes += $bytes;

        if ($this->fieldBytes > $this->maxFieldBytes) {
            throw $this->fieldTooLong();
        }
    }

    private function boundary(bool $endOfRecord): void
    {
        $this->fieldBytes = 0;
        $this->state = self::FIELD_START;

        if ($endOfRecord) {
            $this->delimiters = 0;
        } elseif (++$this->delimiters >= $this->maxFields) {
            throw $this->tooManyFields();
        }
    }

    private function fieldTooLong(): LimitExceeded
    {
        return new LimitExceeded(sprintf('A CSV field holds more than %d bytes; an unbalanced quote may have joined the rest of the file into it.', $this->maxFieldBytes));
    }

    private function tooManyFields(): LimitExceeded
    {
        return new LimitExceeded(sprintf('A CSV record has more than %d fields.', $this->maxFields));
    }
}
