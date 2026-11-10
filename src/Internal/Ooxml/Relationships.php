<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Ooxml;

use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use XMLReader;

/**
 * The internal relationships of one OPC part (a .rels part), with targets resolved to package
 * paths. External targets and targets that climb out of the package are left out.
 *
 * @internal
 */
final class Relationships
{
    /** Largest relationships, workbook or styles part; real ones stay far below a megabyte. */
    public const MAX_PART_BYTES = 67_108_864;

    /**
     * @param  list<array{id: string, type: string, target: string}>  $entries  in document order
     */
    private function __construct(private readonly array $entries) {}

    /**
     * The relationships of the package itself (_rels/.rels).
     */
    public static function ofPackage(SafeZip $zip): self
    {
        return self::read($zip, '_rels/.rels');
    }

    /**
     * The relationships of $part, such as "xl/workbook.xml"; none when its .rels part is missing.
     */
    public static function ofPart(SafeZip $zip, string $part): self
    {
        return self::read($zip, self::relationshipsPath($part));
    }

    /**
     * The first existing target of a relationship type. Types are matched on their last path
     * segment, so both transitional and strict OOXML relationship URIs are recognised.
     */
    public function partOfType(SafeZip $zip, string $type): ?string
    {
        foreach ($this->entries as $relationship) {
            if (str_ends_with($relationship['type'], '/'.$type) && $zip->has($relationship['target'])) {
                return $relationship['target'];
            }
        }

        return null;
    }

    /**
     * @return array<string, string> relationship id => target
     */
    public function targetsById(): array
    {
        $targets = [];

        foreach ($this->entries as $relationship) {
            $targets[$relationship['id']] = $relationship['target'];
        }

        return $targets;
    }

    /**
     * Resolves a target against the directory of its source part ($base, empty or ending in "/");
     * null when it is empty or climbs above the package root.
     */
    public static function resolve(string $base, string $target): ?string
    {
        $path = str_starts_with($target, '/') ? substr($target, 1) : $base.$target;
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                if ($segments === []) {
                    return null;
                }

                array_pop($segments);

                continue;
            }

            $segments[] = $segment;
        }

        return $segments === [] ? null : implode('/', $segments);
    }

    /**
     * "xl/workbook.xml" keeps its relationships in "xl/_rels/workbook.xml.rels".
     */
    public static function relationshipsPath(string $part): string
    {
        $slash = strrpos($part, '/');

        return $slash === false
            ? '_rels/'.$part.'.rels'
            : substr($part, 0, $slash).'/_rels/'.substr($part, $slash + 1).'.rels';
    }

    private static function read(SafeZip $zip, string $relsPath): self
    {
        if (! $zip->has($relsPath)) {
            return new self([]);
        }

        $directory = dirname($relsPath, 2);
        $base = $directory === '.' ? '' : $directory.'/';
        $xml = $zip->xmlReader($relsPath, self::MAX_PART_BYTES);
        $entries = [];

        try {
            while ($xml->read()) {
                if ($xml->nodeType() !== XMLReader::ELEMENT || $xml->localName() !== 'Relationship') {
                    continue;
                }

                $id = $xml->attribute('Id');
                $target = $xml->attribute('Target');

                if ($id === null || $target === null || strcasecmp((string) $xml->attribute('TargetMode'), 'External') === 0) {
                    continue;
                }

                $resolved = self::resolve($base, rawurldecode($target));

                if ($resolved !== null) {
                    $entries[] = ['id' => $id, 'type' => (string) $xml->attribute('Type'), 'target' => $resolved];
                }
            }
        } finally {
            $xml->close();
        }

        return new self($entries);
    }
}
