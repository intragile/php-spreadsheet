<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Xml;

use Closure;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use LogicException;
use XMLReader;

/**
 * Forward-only XML reader hardened against hostile input.
 *
 * Network access, DTD loading, default attributes and entity substitution are disabled, so an
 * external DTD named by a DOCTYPE is never fetched. A DOCTYPE carrying an internal subset is
 * refused outright: that is where entity declarations live, and refusing it closes the door on
 * external-entity (XXE) and entity-expansion attacks regardless of the libxml version.
 *
 * Only fatal (well-formedness) errors reject a document. Recoverable ones such as an undeclared
 * namespace prefix are ignored, and libxml's error list is drained after every step so that a
 * document producing millions of them cannot exhaust memory.
 *
 * @internal
 */
final class SafeXmlReader
{
    private const FLAGS = LIBXML_NONET | LIBXML_COMPACT;

    private const PROLOG_BYTES = 65536;

    private bool $closed = false;

    private bool $sawNode = false;

    /** First fatal error collected for this reader by collectErrors(). */
    private ?string $fatal = null;

    /** Readers currently open; libxml's error mode is process-wide, so it is shared by all of them. */
    private static int $openReaders = 0;

    /** The error mode in effect before the first open reader switched it on. */
    private static bool $savedErrorMode = false;

    /**
     * @param  Closure(): bool|null  $limitExceeded
     * @param  Closure(): string|null  $prolog
     */
    private function __construct(
        private readonly XMLReader $reader,
        private readonly ?Closure $limitExceeded,
        private readonly ?Closure $prolog,
    ) {
        $reader->setParserProperty(XMLReader::LOADDTD, false);
        $reader->setParserProperty(XMLReader::DEFAULTATTRS, false);
        $reader->setParserProperty(XMLReader::VALIDATE, false);
        $reader->setParserProperty(XMLReader::SUBST_ENTITIES, false);
    }

    /**
     * @param  Closure(): bool|null  $limitExceeded  Reports whether an underlying byte budget ran out.
     * @param  Closure(): string|null  $prolog  Returns the first raw bytes of the document, used to
     *                                          inspect a DOCTYPE the parser does not expose; without
     *                                          it every DOCTYPE the parser cannot show is refused.
     */
    public static function fromUri(string $uri, ?Closure $limitExceeded = null, ?Closure $prolog = null): self
    {
        self::requireExtension();
        self::acquireErrorMode();
        $reader = new XMLReader;

        if (@$reader->open($uri, null, self::FLAGS) !== true) {
            self::releaseErrorMode();

            throw new MalformedXml('The XML document cannot be opened.');
        }

        return new self($reader, $limitExceeded, $prolog);
    }

    /**
     * Opens a local file by path. The path is handed to libxml as an escaped file:// URI, so
     * neither percent sequences nor stream-wrapper prefixes in the name are interpreted.
     */
    public static function fromFile(string $path): self
    {
        return self::fromUri(
            self::fileUri($path),
            null,
            static fn (): string => (string) @file_get_contents($path, false, null, 0, self::PROLOG_BYTES),
        );
    }

    public static function fromString(string $xml): self
    {
        self::requireExtension();

        if ($xml === '') {
            throw new MalformedXml('The XML document is empty.');
        }

        self::acquireErrorMode();
        $reader = new XMLReader;

        if (@$reader->XML($xml, null, self::FLAGS) !== true) {
            self::releaseErrorMode();

            throw new MalformedXml('The XML document cannot be parsed.');
        }

        return new self($reader, null, static fn (): string => substr($xml, 0, self::PROLOG_BYTES));
    }

    /**
     * Advances to the next node.
     *
     * @throws MalformedXml when the document is not well-formed or declares a DOCTYPE
     * @throws LimitExceeded when the underlying archive entry ran over its byte budget
     */
    public function read(): bool
    {
        return $this->advance(@$this->reader->read());
    }

    /**
     * Skips the current node's subtree and moves to its next sibling.
     */
    public function next(): bool
    {
        // XMLReader::next() parses the whole subtree in a single call, during which libxml keeps
        // every recoverable error it meets; walking the subtree step by step lets each step drain
        // the error list. The reader ends up on the same node either way.
        if ($this->closed || $this->nodeType() !== XMLReader::ELEMENT || $this->isEmptyElement()) {
            return $this->read();
        }

        $depth = $this->depth();

        while ($this->read()) {
            if ($this->isEndTagAt($depth)) {
                return $this->read();
            }
        }

        return false;
    }

    /**
     * The wrapped reader, for hot loops that cannot afford a method call per node. Its parser
     * settings stay as configured here. Hand over only once read() has reached the document
     * element, so the DOCTYPE check has run. The caller must not change parser properties, must
     * call collectErrors() before trusting what it has read and before handing control elsewhere
     * (after a fatal error readString() returns '', next() still succeeds and the reader unwinds
     * with synthetic end tags), and must treat a document that stops early as corrupt; read()
     * throws the specific cause (malformed XML, exhausted byte budget) when one is known.
     */
    public function raw(): XMLReader
    {
        return $this->reader;
    }

    public function nodeType(): int
    {
        return $this->reader->nodeType;
    }

    public function localName(): string
    {
        return $this->reader->localName;
    }

    public function namespaceUri(): string
    {
        return $this->reader->namespaceURI;
    }

    public function attribute(string $localName, ?string $namespace = null): ?string
    {
        return $namespace === null
            ? $this->reader->getAttribute($localName)
            : $this->reader->getAttributeNs($localName, $namespace);
    }

    public function value(): string
    {
        return $this->reader->value;
    }

    public function isEmptyElement(): bool
    {
        return $this->reader->isEmptyElement;
    }

    public function depth(): int
    {
        return $this->reader->depth;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        @$this->reader->close();
        self::releaseErrorMode();
    }

    /**
     * Closes a reader whose caller is leaving in the middle of its own step, typically on an
     * exception, taking the errors that step left in the process-wide list along so another open
     * reader does not inherit them.
     */
    public function abandon(): void
    {
        if (! $this->closed) {
            $this->collectErrors();
        }

        $this->close();
    }

    /**
     * Moves the errors libxml recorded since the last check to this reader, emptying the
     * process-wide list, and reports whether this reader has met a fatal error. Callers of raw()
     * use it after their own steps, before handing control elsewhere, so an error is always
     * attributed to the reader that caused it. A collected fatal error is thrown by read().
     */
    public function collectErrors(): bool
    {
        $this->fatal ??= $this->drainErrors();

        return $this->fatal !== null;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function isEndTagAt(int $depth): bool
    {
        return $this->reader->nodeType === XMLReader::END_ELEMENT && $this->reader->depth === $depth;
    }

    private function advance(bool $moved): bool
    {
        $error = $this->fatal ?? $this->drainErrors();

        if ($moved && $error === null) {
            if ($this->reader->nodeType === XMLReader::DOC_TYPE && $this->hasInternalSubset()) {
                $this->close();

                throw new MalformedXml('XML documents with an internal DTD subset are not accepted.');
            }

            $this->sawNode = true;

            return true;
        }

        $limitExceeded = $this->limitExceeded !== null && ($this->limitExceeded)();
        $empty = ! $this->sawNode;
        $this->close();

        if ($limitExceeded) {
            throw new LimitExceeded('The archive entry exceeds the size limit.');
        }

        if ($error !== null) {
            throw new MalformedXml('The XML document is not well-formed: '.$error);
        }

        if ($empty) {
            throw new MalformedXml('The XML document has no content.');
        }

        return false;
    }

    /**
     * Returns the first fatal error raised by the last step, then empties libxml's error list.
     *
     * The list only ever holds the errors of a single step, so reading it stays cheap; the last
     * error check skips the copy entirely on the common error-free path.
     */
    private function drainErrors(): ?string
    {
        if (libxml_get_last_error() === false) {
            return null;
        }

        $fatal = null;

        foreach (libxml_get_errors() as $error) {
            if ($error->level === LIBXML_ERR_FATAL) {
                $fatal = trim($error->message);

                break;
            }
        }

        libxml_clear_errors();

        return $fatal;
    }

    /**
     * libxml before 2.14 serialises a DOCTYPE with its internal subset, so the subset shows up
     * either as declarations ("[ ... ]>") or, for comments and processing instructions, as inner
     * markup. Later versions serialise nothing, and the raw prolog is inspected instead.
     */
    private function hasInternalSubset(): bool
    {
        $outer = rtrim(@$this->reader->readOuterXml());
        $inner = @$this->reader->readInnerXml();
        libxml_clear_errors();

        if ($outer === '' && $inner === '') {
            return $this->prolog === null || DoctypeScanner::mayHaveInternalSubset(($this->prolog)());
        }

        return str_ends_with($outer, ']>') || $inner !== '';
    }

    /**
     * Internal error collection stays on while any reader is open, whatever order readers are
     * closed in; the mode found before the first one opened is restored when the last one closes.
     * Errors left over from earlier code are dropped so they are not blamed on this reader.
     */
    private static function acquireErrorMode(): void
    {
        $previous = libxml_use_internal_errors(true);

        if (self::$openReaders === 0) {
            self::$savedErrorMode = $previous;
            libxml_clear_errors();
        }

        self::$openReaders++;
    }

    /**
     * libxml keeps one error list for the whole process, so errors left in it may belong to
     * another open reader that has not collected them yet. They are only dropped once the last
     * reader closes, before the previous error mode is restored.
     */
    private static function releaseErrorMode(): void
    {
        if (self::$openReaders > 0 && --self::$openReaders === 0) {
            libxml_clear_errors();
            libxml_use_internal_errors(self::$savedErrorMode);
        }
    }

    private static function fileUri(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $drive = '';

        if (preg_match('#^[A-Za-z]:/#', $path) === 1) {
            $drive = '/'.substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif (! str_starts_with($path, '/')) {
            $path = str_replace('\\', '/', (string) getcwd()).'/'.$path;
        }

        $segments = array_map('rawurlencode', explode('/', $path));

        return 'file://'.$drive.implode('/', $segments);
    }

    private static function requireExtension(): void
    {
        if (! extension_loaded('xmlreader')) {
            throw new LogicException('ext-xmlreader is required to read XML.');
        }
    }
}
