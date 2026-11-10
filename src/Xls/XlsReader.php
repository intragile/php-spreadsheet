<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xls;

use DateTimeImmutable;
use Generator;
use IntrAgile\Spreadsheet\Contracts\Reader;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Exception\UnsupportedFormat;
use IntrAgile\Spreadsheet\Internal\Date\SerialDate;
use IntrAgile\Spreadsheet\Internal\HeaderKeyedRows;
use IntrAgile\Spreadsheet\ReadOptions;
use IntrAgile\Spreadsheet\SheetInfo;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Reads the rows of a legacy binary Excel workbook (xls) through the optional
 * phpoffice/phpspreadsheet package.
 *
 * Unlike the other readers this one is not streaming: the whole workbook is loaded into memory.
 * The sheet dimensions are read first and the file is rejected with LimitExceeded when the
 * cells of all sheets together exceed ReadOptions::$maxCells, before anything is loaded.
 *
 * Values follow the stored cell type: numbers become int when integral and exactly
 * representable and float otherwise, cells with a date or time format become DateTimeImmutable
 * (UTC wall-clock, honouring the workbook's 1904 date system), booleans stay bool, formulas
 * yield their cached result and empty text is null.
 */
final class XlsReader implements Reader
{
    use HeaderKeyedRows;

    /** Integers beyond ±2^53 lose precision as float, so they stay float to say so. */
    private const MAX_EXACT_INT = 9007199254740992;

    /**
     * Class used to read the file; replaceable so tests can simulate a missing package.
     *
     * @internal
     */
    public static string $adapterClass = Xls::class;

    private ?Workbook $workbook;

    private readonly bool $date1904;

    /**
     * @throws SpreadsheetException when the file cannot be read
     * @throws UnsupportedFormat when phpoffice/phpspreadsheet is not installed
     * @throws LimitExceeded when the sheets hold more cells than ReadOptions::$maxCells
     * @throws CorruptFile when the file is not a readable xls workbook
     */
    public function __construct(private readonly string $path, private readonly ReadOptions $options)
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        $class = self::$adapterClass;

        if (! class_exists($class)) {
            throw new UnsupportedFormat('Reading XLS files requires phpoffice/phpspreadsheet: composer require phpoffice/phpspreadsheet');
        }

        try {
            $adapter = new $class;

            if (! $adapter instanceof BaseReader) {
                throw new UnsupportedFormat('Reading XLS files requires phpoffice/phpspreadsheet: composer require phpoffice/phpspreadsheet');
            }

            $this->checkCellLimit($adapter);

            $adapter->setReadEmptyCells(false);
            $this->workbook = $adapter->load($path);
            $this->date1904 = $this->workbook->getExcelCalendar() === Date::CALENDAR_MAC_1904;
        } catch (SpreadsheetException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new CorruptFile('The XLS file cannot be read: '.$exception->getMessage(), 0, $exception);
        }
    }

    public function sheets(): array
    {
        $sheets = [];

        foreach ($this->workbook()->getAllSheets() as $index => $sheet) {
            $sheets[] = new SheetInfo($sheet->getTitle(), $index, $sheet->getSheetState() === Worksheet::SHEETSTATE_VISIBLE);
        }

        return $sheets;
    }

    public function rows(int|string $sheet = 0): Generator
    {
        return $this->stream($this->worksheet($sheet));
    }

    public function close(): void
    {
        $this->workbook?->disconnectWorksheets();
        $this->workbook = null;
    }

    /**
     * @return Generator<int, list<int|float|string|bool|DateTimeImmutable|null>>
     */
    private function stream(Worksheet $sheet): Generator
    {
        foreach ($sheet->getRowIterator() as $number => $row) {
            $cells = $row->getCellIterator();
            $cells->setIterateOnlyExistingCells(true);

            $values = [];
            $last = -1;

            foreach ($cells as $cell) {
                $value = $this->value($cell);

                if ($value === null) {
                    continue;
                }

                $position = Coordinate::columnIndexFromString($cell->getColumn()) - 1;
                $values += array_fill($last + 1, $position - $last - 1, null);
                $values[$position] = $value;
                $last = $position;
            }

            if ($values !== []) {
                yield $number => array_values($values);
            }
        }
    }

    private function value(Cell $cell): int|float|string|bool|DateTimeImmutable|null
    {
        $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        return match (true) {
            $value === null, $value === '' => null,
            is_bool($value) => $value,
            is_int($value), is_float($value) => $this->number($cell, $value),
            is_string($value) => $value,
            default => null,
        };
    }

    private function number(Cell $cell, int|float $value): int|float|DateTimeImmutable
    {
        if (Date::isDateTime($cell)) {
            try {
                return SerialDate::toDateTime((float) $value, $this->date1904);
            } catch (InvalidArgumentException) {
                // A serial outside the calendar is not a date; the raw number is kept.
            }
        }

        if (is_float($value) && $value === floor($value) && abs($value) < self::MAX_EXACT_INT) {
            return (int) $value;
        }

        return $value;
    }

    private function checkCellLimit(BaseReader $adapter): void
    {
        $cells = 0;

        foreach ($adapter->listWorksheetInfo($this->path) as $info) {
            $cells += (int) $info['totalRows'] * (int) $info['totalColumns'];

            if ($cells > $this->options->maxCells) {
                throw new LimitExceeded(sprintf('The workbook holds more than %d cells, which exceeds maxCells.', $this->options->maxCells));
            }
        }
    }

    private function worksheet(int|string $sheet): Worksheet
    {
        $workbook = $this->workbook();

        $found = is_int($sheet)
            ? ($sheet >= 0 && $sheet < $workbook->getSheetCount() ? $workbook->getSheet($sheet) : null)
            : $workbook->getSheetByName($sheet);

        if ($found !== null) {
            return $found;
        }

        throw new InvalidArgumentException(sprintf(
            is_int($sheet) ? 'Sheet index %d does not exist; available sheets: %s.' : 'Sheet "%s" does not exist; available sheets: %s.',
            $sheet,
            implode(', ', array_map(static fn (SheetInfo $info): string => '"'.$info->name.'"', $this->sheets())) ?: 'none',
        ));
    }

    private function workbook(): Workbook
    {
        return $this->workbook ?? throw new \LogicException('The reader is closed.');
    }
}
