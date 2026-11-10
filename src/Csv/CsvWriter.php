<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Csv;

use DateTimeInterface;
use IntrAgile\Spreadsheet\Cell;
use IntrAgile\Spreadsheet\Contracts\Writer;
use IntrAgile\Spreadsheet\Internal\TempFile;
use IntrAgile\Spreadsheet\SheetOptions;
use IntrAgile\Spreadsheet\Style;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Throwable;

/**
 * Streams rows into a temporary file next to the target and renames it into place on close().
 * Styles and sheet options are ignored; CSV holds a single sheet.
 *
 * Spreadsheet applications run a CSV field that starts with "=", "+", "-" or "@" as a formula
 * (CSV injection). With $escapeFormulas, every string value starting with one of those, a tab or
 * a carriage return is written with a leading apostrophe, which Excel and LibreOffice hide and
 * treat as "show as text". Cell::formula() values are explicit and stay verbatim.
 */
final class CsvWriter implements Writer
{
    /** First characters that make a spreadsheet application evaluate a field. */
    private const FORMULA_TRIGGERS = "=+-@\t\r";

    /** @var resource|null */
    private $handle = null;

    private ?string $tempPath = null;

    private bool $sheetStarted = false;

    private bool $closed = false;

    /** True once partial output was discarded; close() then reports that no file exists. */
    private bool $aborted = false;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ',',
        private readonly bool $bom = false,
        private readonly bool $escapeFormulas = false,
    ) {
        if (strlen($delimiter) !== 1) {
            throw new InvalidArgumentException('The CSV delimiter must be a single byte.');
        }
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->abort();
        }
    }

    public function sheet(string $name, ?SheetOptions $options = null): static
    {
        $this->assertOpen();

        if ($this->sheetStarted) {
            throw new LogicException('CSV supports one sheet');
        }

        $this->sheetStarted = true;

        return $this;
    }

    public function addRow(iterable $values, ?Style $rowStyle = null): static
    {
        $this->assertOpen();
        $this->sheetStarted = true;

        try {
            $handle = $this->handle();
            $fields = [];

            foreach ($values as $value) {
                $fields[] = $this->map($value, count($fields) + 1);
            }

            if (fputcsv($handle, $fields, $this->delimiter, '"', '', "\r\n") === false) {
                throw new RuntimeException('Cannot write to '.$this->tempPath.'.');
            }
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }

        return $this;
    }

    public function addRows(iterable $rows, ?Style $rowStyle = null): static
    {
        $this->assertOpen();

        try {
            foreach ($rows as $row) {
                $this->addRow($row, $rowStyle);
            }
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }

        return $this;
    }

    /**
     * @throws LogicException when an earlier failure aborted the write, so no file was written
     */
    public function close(): void
    {
        if ($this->closed) {
            if ($this->aborted) {
                throw new LogicException('The write was aborted by an earlier error; no file was written to '.$this->path.'.');
            }

            return;
        }

        try {
            $handle = $this->handle();

            if (! fclose($handle)) {
                throw new RuntimeException('Cannot finish writing '.$this->tempPath.'.');
            }

            $this->handle = null;

            TempFile::publish((string) $this->tempPath, $this->path);

            $this->tempPath = null;
            $this->closed = true;
        } catch (Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    private function assertOpen(): void
    {
        if ($this->closed) {
            throw new LogicException('The writer is closed.');
        }
    }

    /**
     * @return resource
     */
    private function handle()
    {
        if ($this->handle !== null) {
            return $this->handle;
        }

        $this->tempPath = TempFile::create('sps_csv_', dirname($this->path));
        $handle = fopen($this->tempPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot open '.$this->tempPath.' for writing.');
        }

        if ($this->bom) {
            fwrite($handle, "\xEF\xBB\xBF");
        }

        return $this->handle = $handle;
    }

    /**
     * Drops the partial output and marks the writer closed; the target is never created.
     */
    private function abort(): void
    {
        if ($this->handle !== null) {
            @fclose($this->handle);
            $this->handle = null;
        }

        if ($this->tempPath !== null) {
            TempFile::delete($this->tempPath);
            $this->tempPath = null;
        }

        $this->closed = true;
        $this->aborted = true;
    }

    /**
     * @throws InvalidArgumentException when the value cannot be written
     */
    private function map(mixed $value, int $column): string
    {
        if ($value instanceof Cell) {
            if ($value->kind === Cell::KIND_FORMULA && is_string($value->value)) {
                return $value->value;
            }

            $value = $value->value;
        }

        return match (true) {
            is_string($value) => $this->escapeFormulas && $value !== '' && str_contains(self::FORMULA_TRIGGERS, $value[0]) ? "'".$value : $value,
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->float($value),
            $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            default => throw new InvalidArgumentException(sprintf('Unsupported cell value of type %s in column %d.', get_debug_type($value), $column)),
        };
    }

    /**
     * Shortest representation that round-trips, independent of the locale.
     */
    private function float(float $value): string
    {
        if (! is_finite($value)) {
            throw new InvalidArgumentException('Spreadsheet numbers must be finite; got '.var_export($value, true).'.');
        }

        $text = var_export($value, true);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
