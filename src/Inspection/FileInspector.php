<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Inspection;

use IntrAgile\Spreadsheet\Detection\FormatDetector;
use IntrAgile\Spreadsheet\Format;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\Spreadsheet;
use InvalidArgumentException;

/**
 * Looks at the start of a file without reading all of it.
 */
final class FileInspector
{
    /**
     * Scans the first non-empty rows of every sheet.
     *
     * @param  int  $scanRows  Most non-empty rows read from each sheet.
     *
     * @throws InvalidArgumentException when $scanRows is below 1
     */
    public static function inspect(string $path, ?ReadOptions $options = null, ?Format $format = null, int $scanRows = 20): Inspection
    {
        if ($scanRows < 1) {
            throw new InvalidArgumentException('scanRows must be at least 1.');
        }

        $format ??= FormatDetector::detect($path);
        $reader = Spreadsheet::read($path, $options, $format);

        try {
            $sheets = [];

            foreach ($reader->sheets() as $info) {
                $rows = [];

                foreach ($reader->rows($info->index) as $number => $row) {
                    $rows[$number] = $row;

                    if (count($rows) >= $scanRows) {
                        break;
                    }
                }

                $sheets[] = new SheetInspection(
                    $info,
                    self::headerRow($rows),
                    self::widest($rows),
                    self::hasData($rows),
                    array_values($rows),
                );
            }
        } finally {
            $reader->close();
        }

        return new Inspection($format, $sheets, self::firstDataSheet($sheets));
    }

    /**
     * Counts the non-empty cells of one sheet and stops as soon as the count passes $stopAbove, so an
     * oversized sheet is recognised without reading all of it.
     *
     * @param  int  $stopAbove  Cell count above which the scan stops.
     * @param  int|string  $sheet  Sheet index or name.
     */
    public static function countCells(string $path, int $stopAbove, int|string $sheet = 0, ?ReadOptions $options = null, ?Format $format = null): CellCount
    {
        $reader = Spreadsheet::read($path, $options, $format);
        $lastRow = 0;
        $columns = 0;
        $cells = 0;
        $exceeded = false;

        try {
            foreach ($reader->rows($sheet) as $number => $row) {
                $width = self::width($row);

                if ($width === 0) {
                    continue;
                }

                $lastRow = $number;
                $columns = max($columns, $width);

                foreach ($row as $cell) {
                    if (! self::isBlank($cell)) {
                        $cells++;
                    }
                }

                if ($cells > $stopAbove) {
                    $exceeded = true;
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        return new CellCount($lastRow, $columns, $cells, $exceeded);
    }

    /**
     * @param  array<int, list<mixed>>  $rows  scanned rows keyed by real row number
     */
    private static function headerRow(array $rows): ?int
    {
        $numbers = array_keys($rows);

        foreach ($numbers as $position => $number) {
            if (self::textCells($rows[$number]) < 2 || ! isset($numbers[$position + 1])) {
                continue;
            }

            if (self::width($rows[$numbers[$position + 1]]) > 0) {
                return $number;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $row
     */
    private static function textCells(array $row): int
    {
        $count = 0;

        foreach ($row as $cell) {
            if (is_string($cell) && trim($cell) !== '' && ! is_numeric(trim($cell))) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<int, list<mixed>>  $rows
     */
    private static function widest(array $rows): int
    {
        return array_reduce($rows, static fn (int $max, array $row): int => max($max, self::width($row)), 0);
    }

    /**
     * @param  array<int, list<mixed>>  $rows
     */
    private static function hasData(array $rows): bool
    {
        foreach ($rows as $row) {
            if (self::width($row) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<mixed>  $row
     */
    private static function width(array $row): int
    {
        for ($i = count($row); $i > 0; $i--) {
            if (! self::isBlank($row[$i - 1])) {
                return $i;
            }
        }

        return 0;
    }

    private static function isBlank(mixed $cell): bool
    {
        return $cell === null || (is_string($cell) && trim($cell) === '');
    }

    /**
     * @param  list<SheetInspection>  $sheets
     */
    private static function firstDataSheet(array $sheets): ?int
    {
        $hidden = null;

        foreach ($sheets as $sheet) {
            if (! $sheet->hasData) {
                continue;
            }

            if ($sheet->info->visible) {
                return $sheet->info->index;
            }

            $hidden ??= $sheet->info->index;
        }

        return $hidden;
    }
}
