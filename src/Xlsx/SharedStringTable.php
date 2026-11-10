<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\TempFile;
use LogicException;

/**
 * The shared-string table of a workbook, held in memory up to a fixed number of entries.
 *
 * Entries beyond the limit spill to a temporary data file as length-prefixed records (a 32-bit
 * length, then the bytes). Their file offsets go to a second temporary file as fixed-width 64-bit
 * integers, so entry N's offset sits at byte N * 8. Memory use is therefore constant however many
 * entries spill: only write buffers and one read-ahead block of each file stay in memory.
 *
 * @internal
 */
final class SharedStringTable
{
    private const OFFSET_BYTES = 8;

    private const WRITE_BUFFER_BYTES = 65536;

    private const READ_BLOCK_BYTES = 65536;

    /** @var list<string> */
    private array $memory = [];

    private int $memoryCount = 0;

    private int $spilledCount = 0;

    /** @var resource|null */
    private $data = null;

    private ?string $dataPath = null;

    /** @var resource|null */
    private $index = null;

    private ?string $indexPath = null;

    /** Bytes written to the data file plus bytes still waiting in the write buffer. */
    private int $size = 0;

    private string $pending = '';

    private string $pendingOffsets = '';

    /** Read-ahead copy of the data file from offset $blockStart. */
    private string $block = '';

    private int $blockStart = 0;

    /** Read-ahead copy of the offset file from byte $offsetBlockStart. */
    private string $offsetBlock = '';

    private int $offsetBlockStart = 0;

    private bool $closed = false;

    /**
     * @param  int  $inMemoryLimit  entries kept in memory before the rest spill to disk
     * @param  int  $maxEntries  most entries the table may hold
     */
    public function __construct(private readonly int $inMemoryLimit, private readonly int $maxEntries = PHP_INT_MAX) {}

    /**
     * @throws LimitExceeded when the table would hold more than $maxEntries entries
     */
    public function add(string $value): void
    {
        if ($this->closed) {
            throw new LogicException('The shared-string table is closed.');
        }

        if ($this->memoryCount + $this->spilledCount >= $this->maxEntries) {
            throw new LimitExceeded(sprintf('The shared-string table holds more than %d entries.', $this->maxEntries));
        }

        if ($this->memoryCount < $this->inMemoryLimit) {
            $this->memory[] = $value;
            $this->memoryCount++;

            return;
        }

        if ($this->data === null) {
            $this->openSpillFiles();
        }

        $this->pendingOffsets .= pack('J', $this->size);
        $this->pending .= pack('V', strlen($value)).$value;
        $this->size += 4 + strlen($value);
        $this->spilledCount++;

        if (strlen($this->pending) >= self::WRITE_BUFFER_BYTES || strlen($this->pendingOffsets) >= self::WRITE_BUFFER_BYTES) {
            $this->flush();
        }
    }

    /**
     * Returns '' for an index outside the table, as spreadsheet applications show a dangling reference.
     *
     * @throws LogicException once the table is closed
     */
    public function get(int $index): string
    {
        if ($this->closed) {
            throw new LogicException('The shared-string table is closed.');
        }

        if ($index < $this->memoryCount) {
            return $index >= 0 ? $this->memory[$index] : '';
        }

        $index -= $this->memoryCount;

        if ($index >= $this->spilledCount || $this->data === null) {
            return '';
        }

        if ($this->pending !== '' || $this->pendingOffsets !== '') {
            $this->flush();
        }

        $offset = $this->offsetOf($index);

        // Cells mostly refer to strings in table order, so a read-ahead block serves most lookups.
        $start = $offset - $this->blockStart;

        if ($start < 0 || $start + 4 > strlen($this->block)) {
            $this->loadBlock($offset);
            $start = 0;
        }

        /** @var array{1: int} $header */
        $header = unpack('V', $this->block, $start);
        $length = $header[1];

        if ($start + 4 + $length > strlen($this->block)) {
            if ($length + 4 > self::READ_BLOCK_BYTES) {
                return $this->readAt($this->data, $offset + 4, $length);
            }

            $this->loadBlock($offset);
            $start = 0;
        }

        return $length === 0 ? '' : substr($this->block, $start + 4, $length);
    }

    /**
     * Entries kept in memory, indexed from 0; get() resolves the rest. Lets a hot loop look up the
     * common case without a method call.
     *
     * @return list<string>
     */
    public function inMemory(): array
    {
        return $this->memory;
    }

    public function count(): int
    {
        return $this->memoryCount + $this->spilledCount;
    }

    public function close(): void
    {
        $this->closed = true;

        foreach ([$this->data, $this->index] as $handle) {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        foreach ([$this->dataPath, $this->indexPath] as $path) {
            if ($path !== null) {
                TempFile::delete($path);
            }
        }

        $this->data = null;
        $this->index = null;
        $this->dataPath = null;
        $this->indexPath = null;
        $this->memory = [];
        $this->memoryCount = 0;
        $this->spilledCount = 0;
        $this->pending = '';
        $this->pendingOffsets = '';
        $this->size = 0;
        $this->block = '';
        $this->blockStart = 0;
        $this->offsetBlock = '';
        $this->offsetBlockStart = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function openSpillFiles(): void
    {
        $this->dataPath = TempFile::create('sps_sst');
        $this->indexPath = TempFile::create('sps_ssi');
        $data = fopen($this->dataPath, 'w+b');
        $index = fopen($this->indexPath, 'w+b');

        if ($data === false || $index === false) {
            if ($data !== false) {
                fclose($data);
            }

            if ($index !== false) {
                fclose($index);
            }

            TempFile::delete($this->dataPath);
            TempFile::delete($this->indexPath);
            $this->dataPath = null;
            $this->indexPath = null;

            throw new SpreadsheetException('Cannot open a temporary file for shared strings.');
        }

        $this->data = $data;
        $this->index = $index;
    }

    /**
     * Data file offset of the $index-th spilled entry.
     */
    private function offsetOf(int $index): int
    {
        $position = $index * self::OFFSET_BYTES;
        $start = $position - $this->offsetBlockStart;

        // The block may be short when it was read before later entries were added.
        if ($start < 0 || $start + self::OFFSET_BYTES > strlen($this->offsetBlock)) {
            $blockStart = $position - $position % self::READ_BLOCK_BYTES;
            $total = $this->spilledCount * self::OFFSET_BYTES;
            $this->offsetBlock = $this->readAt($this->index, $blockStart, min(self::READ_BLOCK_BYTES, $total - $blockStart));
            $this->offsetBlockStart = $blockStart;
            $start = $position - $blockStart;
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $this->offsetBlock, $start);

        return $unpacked[1];
    }

    private function loadBlock(int $offset): void
    {
        $this->block = $this->readAt($this->data, $offset, min(self::READ_BLOCK_BYTES, $this->size - $offset));
        $this->blockStart = $offset;
    }

    /**
     * @param  resource|null  $handle
     */
    private function readAt($handle, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if ($handle === null || fseek($handle, $offset) !== 0) {
            throw new SpreadsheetException('The shared-string spill file cannot be read.');
        }

        $data = fread($handle, $length);

        if ($data === false || strlen($data) !== $length) {
            throw new SpreadsheetException('The shared-string spill file cannot be read.');
        }

        return $data;
    }

    private function flush(): void
    {
        if ($this->data === null || $this->index === null) {
            return;
        }

        foreach ([[$this->data, $this->pending], [$this->index, $this->pendingOffsets]] as [$handle, $bytes]) {
            fseek($handle, 0, SEEK_END);

            if (fwrite($handle, $bytes) !== strlen($bytes)) {
                throw new SpreadsheetException('Cannot write the shared-string spill file.');
            }
        }

        $this->pending = '';
        $this->pendingOffsets = '';
    }
}
