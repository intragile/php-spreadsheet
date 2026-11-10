<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Zip;

use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Internal\Xml\SafeXmlReader;
use LogicException;
use ZipArchive;

/**
 * Read-only access to a zip container (OOXML, OpenDocument) under safety limits.
 *
 * @internal
 */
final class SafeZip
{
    /** Entries up to this size skip the ratio check: tiny, highly repetitive parts are harmless and compress extremely well. */
    private const RATIO_FLOOR_BYTES = 1_048_576;

    /** @var array<string, int> entry name => index */
    private array $entries = [];

    /** @var list<string> */
    private array $uris = [];

    private ByteBudget $total;

    private function __construct(
        private readonly ZipArchive $zip,
        private readonly int $maxUncompressedBytes,
    ) {
        $this->total = new ByteBudget($maxUncompressedBytes);
    }

    /**
     * @param  int  $maxEntries  Most entries the archive may hold.
     * @param  int  $maxUncompressedBytes  Limit for a single entry and for all bytes read in total.
     * @param  int  $maxCompressionRatio  Largest allowed declared-uncompressed to compressed size ratio of one entry larger than 1 MiB.
     *
     * @throws CorruptFile when the file is not a readable zip archive or holds an encrypted entry
     * @throws LimitExceeded when the archive holds too many entries or an entry declares too many bytes
     */
    public static function open(string $path, int $maxEntries, int $maxUncompressedBytes, int $maxCompressionRatio = 200): self
    {
        if (! extension_loaded('zip')) {
            throw new LogicException('ext-zip is required to read zip containers.');
        }

        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new CorruptFile('The file is not a readable zip archive.');
        }

        if ($zip->numFiles > $maxEntries) {
            $count = $zip->numFiles;
            $zip->close();

            throw new LimitExceeded(sprintf('The archive holds %d entries; the limit is %d.', $count, $maxEntries));
        }

        $archive = new self($zip, $maxUncompressedBytes);

        try {
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);

                // An entry whose header cannot be read cannot be vetted, so the archive is refused.
                if (! is_array($stat)) {
                    throw new CorruptFile(sprintf('The archive entry #%d cannot be read.', $index));
                }

                if ($stat['encryption_method'] !== ZipArchive::EM_NONE) {
                    throw new CorruptFile(sprintf('The archive entry "%s" is encrypted.', $stat['name']));
                }

                if ($stat['size'] > $maxUncompressedBytes) {
                    throw new LimitExceeded(sprintf('The archive entry "%s" declares %d bytes; the limit is %d.', $stat['name'], $stat['size'], $maxUncompressedBytes));
                }

                if ($stat['size'] > self::RATIO_FLOOR_BYTES && $stat['comp_size'] > 0 && $maxCompressionRatio < $stat['size'] / $stat['comp_size']) {
                    throw new LimitExceeded(sprintf('The archive entry "%s" has a compression ratio above %d:1.', $stat['name'], $maxCompressionRatio));
                }

                if (self::isSafeName($stat['name'])) {
                    $archive->entries[$stat['name']] = $index;
                }
            }
        } catch (CorruptFile|LimitExceeded $exception) {
            $zip->close();

            throw $exception;
        }

        return $archive;
    }

    public function has(string $name): bool
    {
        return isset($this->entries[$name]);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->entries);
    }

    /**
     * Reads a small entry completely.
     *
     * @throws LimitExceeded when the entry yields more than $maxBytes or a limit is hit
     * @throws CorruptFile when the entry does not exist or cannot be read
     */
    public function read(string $name, int $maxBytes): string
    {
        $entry = $this->entryBudget($name, $maxBytes);
        $stream = $this->zip->getStream($name);

        if (! is_resource($stream)) {
            throw new CorruptFile(sprintf('The archive entry "%s" cannot be read.', $name));
        }

        $contents = '';

        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);

                if (! is_string($chunk) || $chunk === '') {
                    break;
                }

                if (! $entry->consume(strlen($chunk)) || ! $this->total->consume(strlen($chunk))) {
                    throw new LimitExceeded(sprintf('The archive entry "%s" decompressed beyond its declared size or the size limit.', $name));
                }

                $contents .= $chunk;
            }
        } finally {
            fclose($stream);
        }

        return $contents;
    }

    /**
     * Streams an XML entry; the reader throws LimitExceeded once a byte limit is reached.
     *
     * @param  int|null  $maxBytes  Cap for this entry below the archive-wide limit; null applies only the archive-wide limit.
     */
    public function xmlReader(string $name, ?int $maxBytes = null): SafeXmlReader
    {
        $entry = $this->entryBudget($name, $maxBytes ?? $this->maxUncompressedBytes);
        $total = $this->total;
        $uri = ZipEntryStream::register($this->zip, $name, [$entry, $total]);
        $this->uris[] = $uri;

        return SafeXmlReader::fromUri(
            $uri,
            static fn (): bool => $entry->isExceeded() || $total->isExceeded(),
            static fn (): string => ZipEntryStream::head($uri),
        );
    }

    public function close(): void
    {
        foreach ($this->uris as $uri) {
            ZipEntryStream::release($uri);
        }

        $this->uris = [];
        @$this->zip->close();
    }

    public function __destruct()
    {
        foreach ($this->uris as $uri) {
            ZipEntryStream::release($uri);
        }
    }

    private function entryBudget(string $name, int $maxBytes): ByteBudget
    {
        if (! self::isSafeName($name)) {
            throw new LimitExceeded(sprintf('The archive entry name "%s" is not allowed.', $name));
        }

        $index = $this->entries[$name] ?? null;

        if ($index === null) {
            throw new CorruptFile(sprintf('The archive entry "%s" does not exist.', $name));
        }

        $stat = $this->zip->statIndex($index);

        if (! is_array($stat)) {
            throw new CorruptFile(sprintf('The archive entry "%s" cannot be read.', $name));
        }

        $limit = min($maxBytes, $this->maxUncompressedBytes);

        if ($stat['size'] > $limit) {
            throw new LimitExceeded(sprintf('The archive entry "%s" declares %d bytes; the limit is %d.', $name, $stat['size'], $limit));
        }

        // Bytes actually produced are capped at the declared size (with a floor for tiny entries),
        // so a forged header cannot inflate far beyond what it claims. libzip may already refuse a
        // size mismatch itself; this cap is defence in depth.
        $limit = min($limit, max($stat['size'], self::RATIO_FLOOR_BYTES));

        return new ByteBudget($limit);
    }

    private static function isSafeName(string $name): bool
    {
        if ($name === '' || str_contains($name, "\0") || $name[0] === '/' || $name[0] === '\\' || preg_match('/^[A-Za-z]:/', $name) === 1) {
            return false;
        }

        foreach (preg_split('#[/\\\\]#', $name) ?: [] as $segment) {
            if ($segment === '..') {
                return false;
            }
        }

        return true;
    }
}
