<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet;

use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Contracts\Writer;
use IntrAgile\Spreadsheet\Csv\CsvReader;
use IntrAgile\Spreadsheet\Csv\CsvWriter;
use IntrAgile\Spreadsheet\Detection\FormatDetector;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Exception\UnsupportedFormat;
use IntrAgile\Spreadsheet\Ods\OdsReader;
use IntrAgile\Spreadsheet\Ods\OdsWriter;
use IntrAgile\Spreadsheet\Xlsx\XlsxReader;
use IntrAgile\Spreadsheet\Xlsx\XlsxWriter;

/**
 * Entry point for reading and writing spreadsheet files.
 */
final class Spreadsheet
{
    /**
     * Opens a spreadsheet for streaming reads. The format is detected from the file content
     * unless one is given.
     *
     * @throws SpreadsheetException when the file is missing or unreadable
     * @throws UnsupportedFormat when the format is not supported
     */
    public static function read(string $path, ?ReadOptions $options = null, ?Format $format = null): Reader
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SpreadsheetException("File not found or not readable: {$path}");
        }

        $options ??= new ReadOptions;
        $format ??= FormatDetector::detect($path);

        return match ($format) {
            Format::Xlsx => new XlsxReader($path, $options),
            Format::Ods => new OdsReader($path, $options),
            Format::Csv => new CsvReader($path, $options),
        };
    }

    /**
     * Creates a streaming writer. The format is taken from the file extension unless one is given.
     *
     * @throws UnsupportedFormat when the extension is not a writable format
     */
    public static function write(string $path, ?Format $format = null): Writer
    {
        $format ??= self::formatFromExtension($path);

        return match ($format) {
            Format::Xlsx => new XlsxWriter($path),
            Format::Ods => new OdsWriter($path),
            Format::Csv => new CsvWriter($path),
        };
    }

    private static function formatFromExtension(string $path): Format
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'xlsx' => Format::Xlsx,
            'ods' => Format::Ods,
            'csv', 'txt' => Format::Csv,
            'xls' => throw new UnsupportedFormat('XLS writing is not supported; write xlsx, ods or csv instead.'),
            'xlsm' => throw new UnsupportedFormat('XLSM writing is not supported: the workbook would hold no macros; write xlsx instead.'),
            default => throw new UnsupportedFormat($extension === ''
                ? 'Cannot infer the output format: the path has no extension.'
                : "Cannot infer the output format from the extension \"{$extension}\"."),
        };
    }
}
