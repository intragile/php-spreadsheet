<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal;

use DateTimeImmutable;
use DateTimeInterface;
use Generator;

/**
 * Implements Reader::rowsWithHeader() on top of rows() for every reader.
 *
 * @internal
 */
trait HeaderKeyedRows
{
    abstract public function rows(int|string $sheet = 0): Generator;

    public function rowsWithHeader(int|string $sheet = 0, int $headerRow = 1): Generator
    {
        // Resolved eagerly so an unknown sheet fails at the call, not at the first iteration.
        return $this->keyRows($this->rows($sheet), $headerRow);
    }

    /**
     * @param  Generator<int, list<int|float|string|bool|DateTimeImmutable|null>>  $rows
     * @return Generator<int, array<string, int|float|string|bool|DateTimeImmutable|null>>
     */
    private function keyRows(Generator $rows, int $headerRow): Generator
    {
        $headers = null;

        foreach ($rows as $number => $cells) {
            if ($headers === null) {
                // An empty header row is skipped by rows(), so the first row at or after it is used.
                if ($number >= $headerRow) {
                    $headers = self::headerNames($cells);
                }

                continue;
            }

            $keyed = [];

            foreach ($headers as $position => $name) {
                $keyed[$name] = $cells[$position] ?? null;
            }

            // Cells beyond the header keep their position as "column_N".
            for ($position = count($headers), $last = count($cells); $position < $last; $position++) {
                $keyed[self::uniqueName('column_'.($position + 1), $keyed)] = $cells[$position];
            }

            yield $number => $keyed;
        }
    }

    /**
     * @param  list<int|float|string|bool|DateTimeImmutable|null>  $cells
     * @return list<string>
     */
    private static function headerNames(array $cells): array
    {
        $names = [];
        $taken = [];

        foreach ($cells as $position => $cell) {
            $name = trim(match (true) {
                $cell instanceof DateTimeInterface => $cell->format('Y-m-d H:i:s'),
                is_scalar($cell) => (string) $cell,
                default => '',
            });

            $name = self::uniqueName($name === '' ? 'column_'.($position + 1) : $name, $taken);
            $taken[$name] = true;
            $names[] = $name;
        }

        return $names;
    }

    /**
     * @param  array<string, mixed>  $taken
     */
    private static function uniqueName(string $name, array $taken): string
    {
        $candidate = $name;

        for ($suffix = 2; array_key_exists($candidate, $taken); $suffix++) {
            $candidate = $name.'_'.$suffix;
        }

        return $candidate;
    }
}
