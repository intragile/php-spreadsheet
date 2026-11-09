<?php

declare(strict_types=1);

/**
 * Regenerates the committed fixtures: php tests/fixtures/build.php
 *
 * Plain PHP and ZipArchive only, so the output is reproducible on any machine.
 */
const FIXTURES = __DIR__;

/**
 * @param  list<array{string, string, bool}>  $entries  name, contents, stored uncompressed
 */
function write_zip(string $path, array $entries): void
{
    if (is_file($path)) {
        unlink($path);
    }

    $zip = new ZipArchive;

    if ($zip->open($path, ZipArchive::CREATE) !== true) {
        throw new RuntimeException("Cannot create {$path}");
    }

    foreach ($entries as [$name, $contents, $stored]) {
        $zip->addFromString($name, $contents);

        if ($stored) {
            $zip->setCompressionName($name, ZipArchive::CM_STORE);
        }
    }

    $zip->close();
}

function build_detect_xlsx(): void
{
    $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        .'</Types>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        .'</Relationships>';
    $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        .'<sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';
    $workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        .'</Relationships>';
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
        .'<row r="1"><c r="A1"><v>1</v></c></row></sheetData></worksheet>';

    write_zip(FIXTURES.'/detect/minimal.xlsx', [
        ['[Content_Types].xml', $contentTypes, false],
        ['_rels/.rels', $rels, false],
        ['xl/workbook.xml', $workbook, false],
        ['xl/_rels/workbook.xml.rels', $workbookRels, false],
        ['xl/worksheets/sheet1.xml', $sheet, false],
    ]);
}

function build_detect_ods(): void
{
    $content = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" office:version="1.2">'
        .'<office:body><office:spreadsheet><table:table table:name="Sheet1"><table:table-row>'
        .'<table:table-cell office:value-type="float" office:value="1"><text:p>1</text:p></table:table-cell>'
        .'</table:table-row></table:table></office:spreadsheet></office:body></office:document-content>';

    // The mimetype entry must come first and stay uncompressed so the file can be sniffed by magic bytes.
    write_zip(FIXTURES.'/detect/minimal.ods', [
        ['mimetype', 'application/vnd.oasis.opendocument.spreadsheet', true],
        ['content.xml', $content, false],
    ]);
}

function build_detect_csv(): void
{
    file_put_contents(FIXTURES.'/detect/minimal.csv', "name,qty\nwidget,3\n");
}

function build_detect_xls(): void
{
    // OLE2 compound file signature followed by padding; enough for content sniffing.
    file_put_contents(FIXTURES.'/detect/legacy.xls', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 504));
}

function build_detect_numbers(): void
{
    write_zip(FIXTURES.'/detect/sample.numbers', [
        ['Index/Document.iwa', "\x00\x01\x02", false],
        ['Metadata/Properties.plist', '<plist/>', false],
    ]);
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (! is_dir(FIXTURES.'/detect')) {
        mkdir(FIXTURES.'/detect', 0777, true);
    }

    build_detect_xlsx();
    build_detect_ods();
    build_detect_csv();
    build_detect_xls();
    build_detect_numbers();

    echo 'Fixtures written to '.FIXTURES."\n";
}
