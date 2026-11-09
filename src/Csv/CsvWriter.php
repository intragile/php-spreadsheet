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
 */
final class CsvWriter implements Writer
{
    /** @var resource|null */
    private $handle = null;

    private ?string $tempPath = null;

    private bool $sheetStarted = false;

    private bool $closed = false;

    public function __construct(
        private readonly string $path,
        private readonly string $delimiter = ',',
        private readonly bool $bom = false,
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
                $fields[] = $this->map($value);
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

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        try {
            $handle = $this->handle();

            if (! fclose($handle)) {
                throw new RuntimeException('Cannot finish writing '.$this->tempPath.'.');
            }

            $this->handle = null;

            if (! @rename((string) $this->tempPath, $this->path)) {
                throw new RuntimeException('Cannot move the finished file to '.$this->path.'.');
            }

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
    }

    private function map(int|float|string|bool|DateTimeInterface|Cell|null $value): string
    {
        if ($value instanceof Cell) {
            $value = $value->value;
        }

        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? '1' : '0',
            is_int($value) => (string) $value,
            is_float($value) => $this->float($value),
            $value instanceof DateTimeInterface => $value->format($value->format('His') === '000000' ? 'Y-m-d' : 'Y-m-d H:i:s'),
            default => $value,
        };
    }

    /**
     * Shortest representation that round-trips, independent of the locale.
     */
    private function float(float $value): string
    {
        $text = var_export($value, true);

        return str_ends_with($text, '.0') ? substr($text, 0, -2) : $text;
    }
}
