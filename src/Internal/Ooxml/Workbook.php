<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Internal\Ooxml;

use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use XMLReader;

/**
 * The workbook part of a SpreadsheetML package: its sheets in workbook order, its date system
 * and the paths of the shared-string and styles parts, located through the package's
 * relationships with the conventional paths as fallback.
 *
 * @internal
 */
final class Workbook
{
    private const RELATIONSHIP_NAMESPACES = [
        'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
        'http://purl.oclc.org/ooxml/officeDocument/relationships',
    ];

    /** Most sheets a workbook may declare. */
    private const MAX_SHEETS = 4096;

    /**
     * @param  list<array{name: string, part: string|null, visible: bool}>  $sheets  part is null when missing from the package
     * @param  string|null  $sharedStringsPart  null when the package has no shared-string table
     * @param  string|null  $stylesPart  null when the package has no styles part
     */
    private function __construct(
        public readonly array $sheets,
        public readonly bool $date1904,
        public readonly ?string $sharedStringsPart,
        public readonly ?string $stylesPart,
    ) {}

    /**
     * @throws CorruptFile when the package has no workbook part
     * @throws LimitExceeded when the workbook declares too many sheets
     */
    public static function read(SafeZip $zip): self
    {
        $workbook = self::mainPart($zip) ?? throw new CorruptFile('The workbook part is missing.');
        $relationships = Relationships::ofPart($zip, $workbook);
        [$sheets, $date1904] = self::readSheets($zip, $workbook, $relationships->targetsById());

        $sharedStrings = $relationships->partOfType($zip, 'sharedStrings') ?? self::sibling($workbook, 'sharedStrings.xml');
        $styles = $relationships->partOfType($zip, 'styles') ?? self::sibling($workbook, 'styles.xml');

        return new self(
            $sheets,
            $date1904,
            $zip->has($sharedStrings) ? $sharedStrings : null,
            $zip->has($styles) ? $styles : null,
        );
    }

    /**
     * The package's main part, through _rels/.rels, falling back to the conventional path.
     */
    private static function mainPart(SafeZip $zip): ?string
    {
        return Relationships::ofPackage($zip)->partOfType($zip, 'officeDocument')
            ?? ($zip->has('xl/workbook.xml') ? 'xl/workbook.xml' : null);
    }

    /**
     * @param  array<string, string>  $targets  relationship id => part
     * @return array{list<array{name: string, part: string|null, visible: bool}>, bool}
     */
    private static function readSheets(SafeZip $zip, string $workbook, array $targets): array
    {
        $sheets = [];
        $date1904 = false;
        $xml = $zip->xmlReader($workbook, Relationships::MAX_PART_BYTES);

        try {
            while ($xml->read()) {
                if ($xml->nodeType() !== XMLReader::ELEMENT) {
                    continue;
                }

                $name = $xml->localName();

                if ($name === 'workbookPr') {
                    $date1904 = in_array(strtolower(trim((string) $xml->attribute('date1904'))), ['1', 'true'], true);
                } elseif ($name === 'sheet') {
                    if (count($sheets) >= self::MAX_SHEETS) {
                        throw new LimitExceeded(sprintf('The workbook has more than %d sheets.', self::MAX_SHEETS));
                    }

                    $id = null;

                    foreach (self::RELATIONSHIP_NAMESPACES as $namespace) {
                        $id ??= $xml->attribute('id', $namespace);
                    }

                    $state = $xml->attribute('state');

                    $part = $id !== null ? ($targets[$id] ?? null) : null;

                    $sheets[] = [
                        'name' => (string) $xml->attribute('name'),
                        'part' => $part !== null && $zip->has($part) ? $part : null,
                        'visible' => $state !== 'hidden' && $state !== 'veryHidden',
                    ];
                }
            }
        } finally {
            $xml->close();
        }

        return [$sheets, $date1904];
    }

    private static function sibling(string $part, string $name): string
    {
        $slash = strrpos($part, '/');

        return $slash === false ? $name : substr($part, 0, $slash + 1).$name;
    }
}
