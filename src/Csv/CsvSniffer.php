<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use IntrAgile\Spreadsheet\Exception\SpreadsheetException;

/**
 * Detects the encoding and delimiter of a CSV file from a leading sample.
 */
final class CsvSniffer
{
    private const CANDIDATES = [',', ';', "\t", '|'];

    private const MAX_RECORDS = 20;

    public function sniff(string $path, int $sampleBytes = 65_536): CsvDialect
    {
        $sample = is_file($path) && is_readable($path) ? @file_get_contents($path, false, null, 0, max(1, $sampleBytes)) : false;

        if ($sample === false) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        [$encoding, $bomLength] = $this->detectEncoding($sample);
        $text = $this->decode(substr($sample, $bomLength), $encoding);

        return new CsvDialect($this->detectDelimiter($text), '"', $encoding, $bomLength);
    }

    /**
     * @return array{string, int} encoding and BOM length
     */
    private function detectEncoding(string $sample): array
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return ['UTF-8', 3];
        }

        if (str_starts_with($sample, "\xFF\xFE")) {
            return ['UTF-16LE', 2];
        }

        if (str_starts_with($sample, "\xFE\xFF")) {
            return ['UTF-16BE', 2];
        }

        if ($this->isUtf8($sample)) {
            return ['UTF-8', 0];
        }

        // Windows-1250 is what Excel writes on Hungarian systems. ISO-8859-2 cannot be told apart
        // reliably (its letters share bytes with Windows-1250 and its 0x80-0x9F range is unused),
        // so it is only available through ReadOptions::csvEncoding.
        return ['Windows-1250', 0];
    }

    private function isUtf8(string $sample): bool
    {
        if (mb_check_encoding($sample, 'UTF-8')) {
            return true;
        }

        // The sample limit may have cut a multibyte sequence in half.
        for ($cut = 1; $cut <= 3 && $cut < strlen($sample); $cut++) {
            if (mb_check_encoding(substr($sample, 0, -$cut), 'UTF-8')) {
                return true;
            }
        }

        return false;
    }

    private function decode(string $bytes, string $encoding): string
    {
        if ($encoding === 'UTF-8') {
            return $bytes;
        }

        if (str_starts_with($encoding, 'UTF-16') && strlen($bytes) % 2 === 1) {
            $bytes = substr($bytes, 0, -1);
        }

        return CsvDecoder::decode($bytes, $encoding);
    }

    private function detectDelimiter(string $text): string
    {
        $records = $this->records($text);
        $best = ',';
        $bestFrequency = 0;
        $bestCount = 0;

        foreach (self::CANDIDATES as $candidate) {
            $counts = [];

            foreach ($records as $record) {
                $count = substr_count($record, $candidate);

                if ($count > 0) {
                    $counts[$count] = ($counts[$count] ?? 0) + 1;
                }
            }

            if ($counts === []) {
                continue;
            }

            // The most common per-record count tells how consistent the candidate is.
            arsort($counts);
            $count = array_key_first($counts);
            $frequency = $counts[$count];

            if ($frequency > $bestFrequency || ($frequency === $bestFrequency && $count > $bestCount)) {
                $best = $candidate;
                $bestFrequency = $frequency;
                $bestCount = $count;
            }
        }

        return $best;
    }

    /**
     * The first non-empty records with everything inside quotes removed, so delimiters and
     * newlines in quoted cells do not count.
     *
     * @return list<string>
     */
    private function records(string $text): array
    {
        $records = [];
        $current = '';
        $quoted = false;

        for ($i = 0, $length = strlen($text); $i < $length; $i++) {
            $char = $text[$i];

            if ($char === '"') {
                $quoted = ! $quoted;

                continue;
            }

            if ($quoted) {
                continue;
            }

            if ($char === "\n" || $char === "\r") {
                if (trim($current) !== '') {
                    $records[] = $current;

                    if (count($records) >= self::MAX_RECORDS) {
                        return $records;
                    }
                }

                $current = '';

                continue;
            }

            $current .= $char;
        }

        // A trailing record counts only for a single-line sample, where it is the only evidence there is.
        if ($records === [] && ! $quoted && trim($current) !== '') {
            $records[] = $current;
        }

        return $records;
    }
}
