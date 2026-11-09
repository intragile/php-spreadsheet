<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Xlsx;

use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Internal\TempFile;
use LogicException;

/**
 * The shared-string table of a workbook, held in memory up to a fixed number of entries.
 *
 * Entries beyond the limit spill to a temporary file as length-prefixed records (a 32-bit length,
 * then the bytes). Their file offsets are packed as 64-bit integers into binary strings of
 * OFFSETS_PER_CHUNK entries each, so a spilled entry costs eight bytes of memory and the offset
 * table grows without ever copying one large buffer.
 *
 * @internal
 */
final class SharedStringTable
{
    private const OFFSETS_PER_CHUNK = 8192;

    private const CHUNK_SHIFT = 13;

    private const WRITE_BUFFER_BYTES = 65536;

    private const READ_BLOCK_BYTES = 65536;

    /** @var list<string> */
    private array $memory = [];

    private int $memoryCount = 0;

    /** @var array<int, string> chunk number => packed 64-bit offsets of spilled entries */
    private array $offsets = [];

    private int $spilledCount = 0;

    /** @var resource|null */
    private $handle = null;

    private ?string $path = null;

    /** Bytes written to the file plus bytes still waiting in the write buffer. */
    private int $size = 0;

    private string $pending = '';

    /** Read-ahead copy of the spill file from offset $blockStart. */
    private string $block = '';

    private int $blockStart = 0;

    private bool $closed = false;

    public function __construct(private readonly int $inMemoryLimit) {}

    public function add(string $value): void
    {
        if ($this->closed) {
            throw new LogicException('The shared-string table is closed.');
        }

        if ($this->memoryCount < $this->inMemoryLimit) {
            $this->memory[] = $value;
            $this->memoryCount++;

            return;
        }

        if ($this->handle === null) {
            $this->openSpillFile();
        }

        $chunk = $this->spilledCount >> self::CHUNK_SHIFT;

        if (! isset($this->offsets[$chunk])) {
            $this->offsets[$chunk] = '';
        }

        $this->offsets[$chunk] .= pack('J', $this->size);
        $this->pending .= pack('V', strlen($value)).$value;
        $this->size += 4 + strlen($value);
        $this->spilledCount++;

        if (strlen($this->pending) >= self::WRITE_BUFFER_BYTES) {
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

        if ($index >= $this->spilledCount || $this->handle === null) {
            return '';
        }

        if ($this->pending !== '') {
            $this->flush();
        }

        /** @var array{1: int} $unpacked */
        $unpacked = unpack('J', $this->offsets[$index >> self::CHUNK_SHIFT], ($index & (self::OFFSETS_PER_CHUNK - 1)) * 8);
        $offset = $unpacked[1];

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
                return $this->readAt($offset + 4, $length);
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

        if (is_resource($this->handle)) {
            fclose($this->handle);
        }

        if ($this->path !== null) {
            TempFile::delete($this->path);
        }

        $this->handle = null;
        $this->path = null;
        $this->memory = [];
        $this->offsets = [];
        $this->memoryCount = 0;
        $this->spilledCount = 0;
        $this->pending = '';
        $this->size = 0;
        $this->block = '';
        $this->blockStart = 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function openSpillFile(): void
    {
        $this->path = TempFile::create('sps_sst');
        $handle = fopen($this->path, 'w+b');

        if ($handle === false) {
            TempFile::delete($this->path);
            $this->path = null;

            throw new SpreadsheetException('Cannot open a temporary file for shared strings.');
        }

        $this->handle = $handle;
    }

    private function loadBlock(int $offset): void
    {
        $this->block = $this->readAt($offset, min(self::READ_BLOCK_BYTES, $this->size - $offset));
        $this->blockStart = $offset;
    }

    private function readAt(int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        if ($this->handle === null || fseek($this->handle, $offset) !== 0) {
            throw new SpreadsheetException('The shared-string spill file cannot be read.');
        }

        $data = fread($this->handle, $length);

        if ($data === false || strlen($data) !== $length) {
            throw new SpreadsheetException('The shared-string spill file cannot be read.');
        }

        return $data;
    }

    private function flush(): void
    {
        if ($this->handle === null) {
            return;
        }

        fseek($this->handle, 0, SEEK_END);

        if (fwrite($this->handle, $this->pending) !== strlen($this->pending)) {
            throw new SpreadsheetException('Cannot write the shared-string spill file.');
        }

        $this->pending = '';
    }
}
