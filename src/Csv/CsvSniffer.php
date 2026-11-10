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

    /** Bytes of the Hungarian accented letters (áéíóöőúüű and capitals) in Windows-1250. */
    private const WINDOWS_1250_LETTERS = "\xC1\xC9\xCD\xD3\xD5\xD6\xDA\xDB\xDC\xE1\xE9\xED\xF3\xF5\xF6\xFA\xFB\xFC";

    /** Bytes of the same letters in Mac OS Central European. */
    private const MAC_CENTRAL_EUROPE_LETTERS = "\x83\x85\x86\x87\x8E\x92\x97\x9A\x9C\x9F\xCC\xCE\xE7\xEA\xEE\xF2\xF4\xF5";

    public function sniff(string $path, int $sampleBytes = 65_536): CsvDialect
    {
        $sample = is_file($path) && is_readable($path) ? @file_get_contents($path, false, null, 0, max(1, $sampleBytes)) : false;

        if ($sample === false) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        [$encoding, $bomLength] = $this->detectEncoding($sample);
        $text = $this->decode(substr($sample, $bomLength), $encoding);

        return new CsvDialect($this->detectDelimiter($text), '"', $encoding, $bomLength, self::hasCarriageReturnLines($text));
    }

    /**
     * Whether records end with a bare carriage return (classic Mac OS, Excel for Mac's "CSV Mac"):
     * bare carriage returns outnumber line feeds, which then only occur inside quoted fields.
     */
    public static function hasCarriageReturnLines(string $text): bool
    {
        $bare = substr_count($text, "\r") - substr_count($text, "\r\n");

        return $bare > 0 && $bare > substr_count($text, "\n");
    }

    /**
     * @return array{string, int} encoding and BOM length
     */
    private function detectEncoding(string $sample): array
    {
        if (str_starts_with($sample, "\xEF\xBB\xBF")) {
            return ['UTF-8', 3];
        }

        // The UTF-32LE mark starts with the UTF-16LE one, so it is checked first.
        if (str_starts_with($sample, "\xFF\xFE\x00\x00")) {
            return ['UTF-32LE', 4];
        }

        if (str_starts_with($sample, "\x00\x00\xFE\xFF")) {
            return ['UTF-32BE', 4];
        }

        if (str_starts_with($sample, "\xFF\xFE")) {
            return ['UTF-16LE', 2];
        }

        if (str_starts_with($sample, "\xFE\xFF")) {
            return ['UTF-16BE', 2];
        }

        // NUL is valid UTF-8, so unmarked UTF-16 and UTF-32 must be recognised first.
        $unmarked = self::unicodeWithoutMark($sample);

        if ($unmarked !== null) {
            return [$unmarked, 0];
        }

        if ($this->isUtf8($sample)) {
            return ['UTF-8', 0];
        }

        // Excel for Mac writes CSV in the Mac code page instead: the one in which more bytes are
        // Hungarian accented letters wins.
        if (self::letterCount($sample, self::MAC_CENTRAL_EUROPE_LETTERS) > self::letterCount($sample, self::WINDOWS_1250_LETTERS)) {
            return ['MacCentralEurope', 0];
        }

        // Windows-1250 is what Excel writes on Hungarian systems. ISO-8859-2 cannot be told apart
        // reliably (its letters share bytes with Windows-1250 and its 0x80-0x9F range is unused),
        // so it is only available through ReadOptions::csvEncoding.
        return ['Windows-1250', 0];
    }

    /**
     * Recognises UTF-16 and UTF-32 without a byte-order mark by where the NUL bytes fall. Text in
     * those encodings is mostly Latin or punctuation, so one byte of every UTF-16 code unit, and
     * the upper two bytes of every UTF-32 one, are zero; CSV in any other encoding has no NULs.
     */
    public static function unicodeWithoutMark(string $sample): ?string
    {
        $length = strlen($sample) - strlen($sample) % 4;

        if ($length < 4 || ! str_contains($sample, "\0")) {
            return null;
        }

        $zeros = [0, 0, 0, 0];

        for ($i = 0; $i < $length; $i++) {
            if ($sample[$i] === "\0") {
                $zeros[$i % 4]++;
            }
        }

        $units = intdiv($length, 4);

        // No code point exceeds U+10FFFF, so the most significant byte of a UTF-32 unit is zero.
        if ($zeros[3] === $units && $zeros[2] >= 0.9 * $units && $zeros[0] < $units) {
            return 'UTF-32LE';
        }

        if ($zeros[0] === $units && $zeros[1] >= 0.9 * $units && $zeros[3] < $units) {
            return 'UTF-32BE';
        }

        $pairs = 2 * $units;
        $even = $zeros[0] + $zeros[2];
        $odd = $zeros[1] + $zeros[3];

        if ($odd >= 0.5 * $pairs && $even <= 0.02 * $pairs) {
            return 'UTF-16LE';
        }

        if ($even >= 0.5 * $pairs && $odd <= 0.02 * $pairs) {
            return 'UTF-16BE';
        }

        return null;
    }

    private static function letterCount(string $sample, string $letters): int
    {
        $count = 0;

        foreach (str_split($letters) as $letter) {
            $count += substr_count($sample, $letter);
        }

        return $count;
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

        // The sample limit may have cut the last code unit in half.
        $unit = str_starts_with($encoding, 'UTF-32') ? 4 : (str_starts_with($encoding, 'UTF-16') ? 2 : 1);
        $bytes = substr($bytes, 0, strlen($bytes) - strlen($bytes) % $unit);

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
