<?php

declare(strict_types=1);
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls;

/**
 * Regenerates the committed fixtures: php tests/fixtures/build.php
 *
 * Plain PHP and ZipArchive only, so the output is reproducible on any machine; the xls fixtures
 * additionally need phpoffice/phpspreadsheet (a dev dependency).
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

function build_detect_word_doc(): void
{
    $free = pack('V', 0xFFFFFFFF);
    $end = pack('V', 0xFFFFFFFE);
    $dataSectors = 8;

    $header = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 16)
        .pack('vvvvv', 0x003E, 3, 0xFFFE, 9, 6).str_repeat("\0", 6)
        .pack('VVVVVVVVV', 0, 1, 1, 0, 4096, 0xFFFFFFFE, 0, 0xFFFFFFFE, 0)
        .pack('V', 0).str_repeat($free, 108);

    // Sector 0 is the FAT, sector 1 the directory, sectors 2..9 the stream chain.
    $fat = pack('V', 0xFFFFFFFD).$end;

    for ($sector = 2; $sector < 2 + $dataSectors; $sector++) {
        $fat .= $sector === 1 + $dataSectors ? $end : pack('V', $sector + 1);
    }

    $fat = str_pad($fat, 512, $free);

    $entry = static fn (string $name, int $type, int $child, int $start, int $size): string => str_pad(mb_convert_encoding($name, 'UTF-16LE', 'UTF-8')."\0\0", 64, "\0")
        .pack('vCC', $name === '' ? 0 : (mb_strlen($name) + 1) * 2, $type, 1)
        .$free.$free.pack('V', $child).str_repeat("\0", 16 + 4 + 16)
        .pack('VVV', $start, $size, 0);

    $unused = str_repeat("\0", 66).$free.$free.$free.str_repeat("\0", 36).str_repeat("\0", 12);

    $directory = $entry('Root Entry', 5, 1, 0xFFFFFFFE, 0)
        .$entry('WordDocument', 2, 0xFFFFFFFF, 2, 4096)
        .$unused.$unused;

    file_put_contents(
        FIXTURES.'/detect/word.doc',
        $header.$fat.str_pad($directory, 512, "\0").str_repeat("\0", 512 * $dataSectors),
    );
}

function build_xls_workbooks(): void
{
    require_once dirname(__DIR__, 2).'/vendor/autoload.php';

    $write = static function (Spreadsheet $workbook, string $name): void {
        (new Xls($workbook))->save(FIXTURES.'/xls/'.$name);
        $workbook->disconnectWorksheets();
    };
    $dateFormat = NumberFormat::FORMAT_DATE_YYYYMMDD;
    $text = DataType::TYPE_STRING;

    // Row 1 headers, row 2 typed values, row 3 empty, row 4 formula and a trailing text cell.
    $mixed = new Spreadsheet;
    $sheet = $mixed->getActiveSheet()->setTitle('Data');
    $sheet->fromArray(['id', 'ratio', 'name', 'flag', 'day', 'note', 'code'], null, 'A1');
    $sheet->setCellValue('A2', 1);
    $sheet->setCellValue('B2', 1.5);
    $sheet->setCellValue('C2', 'text');
    $sheet->setCellValue('D2', true);
    $sheet->setCellValue('E2', Date::PHPToExcel(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC'))));
    $sheet->getStyle('E2')->getNumberFormat()->setFormatCode($dateFormat);
    $sheet->setCellValueExplicit('G2', '00123', $text);
    $sheet->setCellValue('A4', 2);
    $sheet->setCellValue('B4', '=A4*2');
    $sheet->setCellValue('C4', 'tail');
    $write($mixed, 'mixed.xls');

    $multi = new Spreadsheet;
    $multi->getActiveSheet()->setTitle('First')->fromArray([['a', 'b'], [1, 2]], null, 'A1');
    $multi->createSheet()->setTitle('Second')->fromArray([['x'], ['y']], null, 'A1');
    $hidden = $multi->createSheet()->setTitle('Hidden');
    $hidden->setCellValue('A1', 'secret');
    $hidden->setSheetState(Worksheet::SHEETSTATE_HIDDEN);
    $write($multi, 'multi-sheet.xls');

    $mac = new Spreadsheet;
    $mac->setExcelCalendar(Date::CALENDAR_MAC_1904);
    Date::setExcelCalendar(Date::CALENDAR_MAC_1904);
    $macSheet = $mac->getActiveSheet();
    $macSheet->setCellValue('A1', Date::PHPToExcel(new DateTimeImmutable('2026-09-14 00:00:00', new DateTimeZone('UTC'))));
    Date::setExcelCalendar(Date::CALENDAR_WINDOWS_1900);
    $macSheet->getStyle('A1')->getNumberFormat()->setFormatCode($dateFormat);
    $write($mac, 'date1904.xls');

    $grid = new Spreadsheet;
    $grid->getActiveSheet()->fromArray(array_fill(0, 5, [1, 2, 3, 4, 5]), null, 'A1');
    $write($grid, 'grid-5x5.xls');
}

function build_detect_numbers(): void
{
    write_zip(FIXTURES.'/detect/sample.numbers', [
        ['Index/Document.iwa', "\x00\x01\x02", false],
        ['Metadata/Properties.plist', '<plist/>', false],
    ]);
}

function build_csv_hu_excel_1250(): void
{
    // Excel on a Hungarian Windows install: Windows-1250, semicolon delimiter, decimal comma, no BOM.
    $utf8 = "Név;Összeg;Város\r\nÁrvíztűrő tükörfúrógép;1 234,56;Győr\r\nŐrmester Ügyfél;99,5;Szőlős\r\n";

    file_put_contents(FIXTURES.'/csv/hu-excel-1250.csv', iconv('UTF-8', 'Windows-1250', $utf8));
}

function build_csv_utf8_bom_comma(): void
{
    file_put_contents(FIXTURES.'/csv/utf8-bom-comma.csv', "\xEF\xBB\xBFid,name\n1,Alice\n2,Bob\n");
}

function build_csv_utf16le_tab(): void
{
    $utf8 = "id\tname\r\n1\tÁrpád\r\n2\tZoltán\r\n";

    file_put_contents(FIXTURES.'/csv/utf16le-tab.txt', "\xFF\xFE".mb_convert_encoding($utf8, 'UTF-16LE', 'UTF-8'));
}

function build_csv_quoted_multiline(): void
{
    file_put_contents(FIXTURES.'/csv/quoted-multiline.csv', "id,note\n1,\"line one\nline two\"\n2,plain\n");
}

const XML_DECL = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\r\n";

const NS_MAIN = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

const NS_PKG_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';

/**
 * @param  list<array{string, string, string}>  $relationships  id, type suffix, target
 */
function xlsx_rels(array $relationships): string
{
    $xml = XML_DECL.'<Relationships xmlns="'.NS_PKG_REL.'">';

    foreach ($relationships as [$id, $type, $target]) {
        $xml .= '<Relationship Id="'.$id.'" Type="'.NS_REL.'/'.$type.'" Target="'.$target.'"/>';
    }

    return $xml.'</Relationships>';
}

/**
 * @param  list<string>  $sheetParts  part names without the leading slash
 */
function xlsx_content_types(array $sheetParts, bool $sharedStrings, bool $docProps): string
{
    $xml = XML_DECL.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';

    foreach ($sheetParts as $part) {
        $xml .= '<Override PartName="/'.$part.'" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $xml .= '<Override PartName="/xl/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
        .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';

    if ($sharedStrings) {
        $xml .= '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>';
    }

    if ($docProps) {
        $xml .= '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            .'<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
    }

    return $xml.'</Types>';
}

function xlsx_package_rels(bool $docProps): string
{
    $xml = XML_DECL.'<Relationships xmlns="'.NS_PKG_REL.'">'
        .'<Relationship Id="rId1" Type="'.NS_REL.'/officeDocument" Target="xl/workbook.xml"/>';

    if ($docProps) {
        $xml .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            .'<Relationship Id="rId3" Type="'.NS_REL.'/extended-properties" Target="docProps/app.xml"/>';
    }

    return $xml.'</Relationships>';
}

function xlsx_app_props(string $application): string
{
    return XML_DECL.'<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
        .'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
        .'<Application>'.$application.'</Application><DocSecurity>0</DocSecurity><ScaleCrop>false</ScaleCrop></Properties>';
}

function xlsx_core_props(): string
{
    return XML_DECL.'<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
        .'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
        .'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        .'<dc:creator>IntrAgile</dc:creator>'
        .'<dcterms:created xsi:type="dcterms:W3CDTF">2025-01-31T08:00:00Z</dcterms:created></cp:coreProperties>';
}

function xlsx_theme(): string
{
    return XML_DECL.'<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Office Theme"><a:themeElements/></a:theme>';
}

/**
 * @param  list<string>  $items  raw <si> inner XML
 */
function xlsx_sst(array $items, int $count): string
{
    $xml = XML_DECL.'<sst xmlns="'.NS_MAIN.'" count="'.$count.'" uniqueCount="'.count($items).'">';

    foreach ($items as $item) {
        $xml .= '<si>'.$item.'</si>';
    }

    return $xml.'</sst>';
}

/**
 * Styles part as Excel writes it: custom numFmts from 164 up, then cellXfs referencing them.
 *
 * @param  array<int, string>  $numFmts  numFmtId => escaped format code
 * @param  list<int>  $xfNumFmtIds  numFmtId of each cellXfs entry
 */
function xlsx_excel_styles(array $numFmts, array $xfNumFmtIds): string
{
    $xml = XML_DECL.'<styleSheet xmlns="'.NS_MAIN.'" xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" '
        .'mc:Ignorable="x14ac x16r2" xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac" '
        .'xmlns:x16r2="http://schemas.microsoft.com/office/spreadsheetml/2015/02/main">';

    if ($numFmts !== []) {
        $xml .= '<numFmts count="'.count($numFmts).'">';

        foreach ($numFmts as $id => $code) {
            $xml .= '<numFmt numFmtId="'.$id.'" formatCode="'.$code.'"/>';
        }

        $xml .= '</numFmts>';
    }

    $xml .= '<fonts count="1" x14ac:knownFonts="1"><font><sz val="11"/><color theme="1"/><name val="Calibri"/><family val="2"/><scheme val="minor"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        .'<cellXfs count="'.count($xfNumFmtIds).'">';

    foreach ($xfNumFmtIds as $id) {
        $xml .= $id === 0
            ? '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            : '<xf numFmtId="'.$id.'" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
    }

    // A conditional-format numFmt must not be mistaken for a cell format.
    return $xml.'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        .'<dxfs count="1"><dxf><numFmt numFmtId="14" formatCode="0.00"/></dxf></dxfs>'
        .'<tableStyles count="0" defaultTableStyle="TableStyleMedium2" defaultPivotStyle="PivotStyleLight16"/></styleSheet>';
}

function xlsx_excel_workbook(string $sheetsXml, bool $date1904 = false): string
{
    return XML_DECL.'<workbook xmlns="'.NS_MAIN.'" xmlns:r="'.NS_REL.'" '
        .'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x15 xr xr6 xr10 xr2" '
        .'xmlns:x15="http://schemas.microsoft.com/office/spreadsheetml/2010/11/main" '
        .'xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision" '
        .'xmlns:xr6="http://schemas.microsoft.com/office/spreadsheetml/2016/revision6" '
        .'xmlns:xr10="http://schemas.microsoft.com/office/spreadsheetml/2016/revision10" '
        .'xmlns:xr2="http://schemas.microsoft.com/office/spreadsheetml/2015/revision2">'
        .'<fileVersion appName="xl" lastEdited="7" lowestEdited="7" rupBuild="28228"/>'
        .'<workbookPr'.($date1904 ? ' date1904="1"' : '').' defaultThemeVersion="166925"/>'
        .'<bookViews><workbookView xWindow="-120" yWindow="-120" windowWidth="29040" windowHeight="15840"/></bookViews>'
        .'<sheets>'.$sheetsXml.'</sheets><calcPr calcId="191029"/></workbook>';
}

function xlsx_excel_sheet(string $dimension, string $sheetData): string
{
    return XML_DECL.'<worksheet xmlns="'.NS_MAIN.'" xmlns:r="'.NS_REL.'" '
        .'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" mc:Ignorable="x14ac xr xr2 xr3" '
        .'xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac" '
        .'xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision" '
        .'xmlns:xr2="http://schemas.microsoft.com/office/spreadsheetml/2015/revision2" '
        .'xmlns:xr3="http://schemas.microsoft.com/office/spreadsheetml/2016/revision3" xr:uid="{00000000-0001-0000-0000-000000000000}">'
        .'<dimension ref="'.$dimension.'"/>'
        .'<sheetViews><sheetView tabSelected="1" workbookViewId="0"/></sheetViews>'
        .'<sheetFormatPr defaultRowHeight="15" x14ac:dyDescent="0.25"/>'
        .'<sheetData>'.$sheetData.'</sheetData>'
        .'<pageMargins left="0.7" right="0.7" top="0.75" bottom="0.75" header="0.3" footer="0.3"/></worksheet>';
}

/**
 * An Excel workbook with one or more worksheets, shared strings and Excel's styles part.
 *
 * @param  list<array{string, string, string, string}>  $sheets  name, state attribute ('' for visible), dimension, sheetData
 * @param  list<string>  $sst  raw <si> inner XML
 * @param  array<int, string>  $numFmts
 * @param  list<int>  $xfNumFmtIds
 */
function write_excel_xlsx(string $path, array $sheets, array $sst, int $sstCount, array $numFmts, array $xfNumFmtIds, bool $date1904 = false): void
{
    $sheetsXml = '';
    $workbookRels = [];
    $entries = [];
    $parts = [];

    foreach ($sheets as $position => [$name, $state, $dimension, $data]) {
        $number = $position + 1;
        $sheetsXml .= '<sheet name="'.$name.'" sheetId="'.$number.'"'.($state !== '' ? ' state="'.$state.'"' : '').' r:id="rId'.$number.'"/>';
        $workbookRels[] = ['rId'.$number, 'worksheet', 'worksheets/sheet'.$number.'.xml'];
        $parts[] = 'xl/worksheets/sheet'.$number.'.xml';
        $entries[] = ['xl/worksheets/sheet'.$number.'.xml', xlsx_excel_sheet($dimension, $data), false];
    }

    $next = count($sheets) + 1;
    $workbookRels[] = ['rId'.$next, 'theme', 'theme/theme1.xml'];
    $workbookRels[] = ['rId'.($next + 1), 'styles', 'styles.xml'];

    if ($sst !== []) {
        $workbookRels[] = ['rId'.($next + 2), 'sharedStrings', 'sharedStrings.xml'];
        $entries[] = ['xl/sharedStrings.xml', xlsx_sst($sst, $sstCount), false];
    }

    write_zip($path, [
        ['[Content_Types].xml', xlsx_content_types($parts, $sst !== [], true), false],
        ['_rels/.rels', xlsx_package_rels(true), false],
        ['docProps/app.xml', xlsx_app_props('Microsoft Excel'), false],
        ['docProps/core.xml', xlsx_core_props(), false],
        ['xl/workbook.xml', xlsx_excel_workbook($sheetsXml, $date1904), false],
        ['xl/_rels/workbook.xml.rels', xlsx_rels($workbookRels), false],
        ['xl/theme/theme1.xml', xlsx_theme(), false],
        ['xl/styles.xml', xlsx_excel_styles($numFmts, $xfNumFmtIds), false],
        ...$entries,
    ]);
}

function build_xlsx_excel_mixed(): void
{
    $sst = [
        '<t>Egész</t>', '<t>Tört</t>', '<t>Szöveg</t>', '<t>Logikai</t>', '<t>Dátum</t>', '<t>Üres</t>', '<t>Utolsó</t>',
        '<t>szöveg</t>', '<t>utolsó</t>', '<t>x</t>',
    ];
    // cellXfs: 0 General, 1 built-in date 14, 2 yyyy.mm.dd, 3 Hungarian long date, 4 built-in 0.00%, 5 forint, 6 built-in 0.00
    $numFmts = [
        164 => 'yyyy\.mm\.dd',
        165 => '[$-40E]yyyy\.\ mmmm\ d\.',
        166 => '#,##0 &quot;Ft&quot;',
    ];
    $data = '<row r="1" spans="1:7" x14ac:dyDescent="0.25">'
        .'<c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="s"><v>2</v></c><c r="D1" t="s"><v>3</v></c>'
        .'<c r="E1" t="s"><v>4</v></c><c r="F1" t="s"><v>5</v></c><c r="G1" t="s"><v>6</v></c></row>'
        .'<row r="2" spans="1:7" x14ac:dyDescent="0.25">'
        .'<c r="A2"><v>42</v></c><c r="B2"><v>3.14</v></c><c r="C2" t="s"><v>7</v></c><c r="D2" t="b"><v>1</v></c>'
        .'<c r="E2" s="1"><v>45688</v></c><c r="F2" s="6"/><c r="G2" t="s"><v>8</v></c></row>'
        .'<row r="3" spans="1:4" x14ac:dyDescent="0.25">'
        .'<c r="A3" s="2"><v>45688</v></c><c r="B3" s="3"><v>45688</v></c><c r="C3" s="4"><v>0.125</v></c><c r="D3" s="5"><v>1500</v></c></row>'
        .'<row r="4" spans="1:4" x14ac:dyDescent="0.25">'
        .'<c r="A4"><f>A2*2</f><v>84</v></c><c r="B4" t="str"><f>C2&amp;"!"</f><v>szöveg!</v></c>'
        .'<c r="C4" t="e"><f>NA()</f><v>#N/A</v></c><c r="D4" t="b"><v>0</v></c></row>'
        .'<row r="5" spans="3:3" x14ac:dyDescent="0.25"><c r="C5" t="s"><v>9</v></c></row>'
        .'<row r="6" spans="1:2" x14ac:dyDescent="0.25"><c r="A6" s="6"/><c r="B6" s="1"/></row>'
        .'<row r="7" spans="1:4" x14ac:dyDescent="0.25">'
        .'<c r="A7"><v>-7</v></c><c r="B7"><v>1E+20</v></c><c r="C7"><v>1E-3</v></c><c r="D7"><v>9007199254740993</v></c></row>';

    write_excel_xlsx(
        FIXTURES.'/xlsx/excel-mixed.xlsx',
        [['Munka1', '', 'A1:G7', $data]],
        $sst,
        12,
        $numFmts,
        [0, 14, 164, 165, 10, 166, 2],
    );
}

function build_xlsx_excel_1904(): void
{
    // 2025-01-31 is serial 44226 in the 1904 date system (45688 in the 1900 system).
    $data = '<row r="1" spans="1:1" x14ac:dyDescent="0.25"><c r="A1" t="s"><v>0</v></c></row>'
        .'<row r="2" spans="1:1" x14ac:dyDescent="0.25"><c r="A2" s="1"><v>44226</v></c></row>'
        .'<row r="3" spans="1:1" x14ac:dyDescent="0.25"><c r="A3" s="2"><v>44226.5</v></c></row>';

    write_excel_xlsx(
        FIXTURES.'/xlsx/excel-1904.xlsx',
        [['Munka1', '', 'A1:A3', $data]],
        ['<t>Dátum</t>'],
        1,
        [164 => 'yyyy\-mm\-dd\ hh:mm'],
        [0, 14, 164],
        true,
    );
}

function build_xlsx_multi_sheet(): void
{
    // Sheet parts are numbered differently from the workbook order, so only the relationships
    // part tells which file belongs to which sheet.
    $sheetData = static fn (string $label): string => '<row r="1" spans="1:1" x14ac:dyDescent="0.25"><c r="A1" t="inlineStr"><is><t>'.$label.'</t></is></c></row>';
    $workbook = XML_DECL.'<workbook xmlns="'.NS_MAIN.'" xmlns:r="'.NS_REL.'">'
        .'<workbookPr defaultThemeVersion="166925"/>'
        .'<sheets>'
        .'<sheet name="Első" sheetId="1" r:id="rId3"/>'
        .'<sheet name="Rejtett" sheetId="2" state="hidden" r:id="rId1"/>'
        .'<sheet name="Nagyon rejtett" sheetId="3" state="veryHidden" r:id="rId2"/>'
        .'<sheet name="Utolsó" sheetId="4" r:id="rId4"/>'
        .'</sheets><calcPr calcId="191029"/></workbook>';
    $rels = xlsx_rels([
        ['rId1', 'worksheet', 'worksheets/sheet1.xml'],
        ['rId2', 'worksheet', 'worksheets/sheet2.xml'],
        ['rId3', 'worksheet', 'worksheets/sheet3.xml'],
        ['rId4', 'worksheet', 'worksheets/sheet4.xml'],
        ['rId5', 'theme', 'theme/theme1.xml'],
        ['rId6', 'styles', 'styles.xml'],
    ]);
    $parts = ['xl/worksheets/sheet1.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/worksheets/sheet4.xml'];

    write_zip(FIXTURES.'/xlsx/multi-sheet.xlsx', [
        ['[Content_Types].xml', xlsx_content_types($parts, false, true), false],
        ['_rels/.rels', xlsx_package_rels(true), false],
        ['docProps/app.xml', xlsx_app_props('Microsoft Excel'), false],
        ['docProps/core.xml', xlsx_core_props(), false],
        ['xl/workbook.xml', $workbook, false],
        ['xl/_rels/workbook.xml.rels', $rels, false],
        ['xl/theme/theme1.xml', xlsx_theme(), false],
        ['xl/styles.xml', xlsx_excel_styles([], [0]), false],
        ['xl/worksheets/sheet1.xml', xlsx_excel_sheet('A1', $sheetData('rejtett')), false],
        ['xl/worksheets/sheet2.xml', xlsx_excel_sheet('A1', $sheetData('nagyon rejtett')), false],
        ['xl/worksheets/sheet3.xml', xlsx_excel_sheet('A1', $sheetData('első')), false],
        ['xl/worksheets/sheet4.xml', xlsx_excel_sheet('A1', $sheetData('utolsó')), false],
    ]);
}

function build_xlsx_inline_strings(): void
{
    $sst = [
        // Rich text with a phonetic guide, which is not part of the value.
        '<r><rPr><b/><sz val="11"/><rFont val="Calibri"/></rPr><t>Közös</t></r><r><rPr><sz val="11"/><rFont val="Calibri"/></rPr><t xml:space="preserve"> szöveg</t></r>'
            .'<rPh sb="0" eb="1"><t>ふりがな</t></rPh><phoneticPr fontId="1"/>',
        '<t>sor1_x000D_sor2 _x005F_x000D_</t>',
    ];
    $data = '<row r="1" spans="1:3" x14ac:dyDescent="0.25">'
        .'<c r="A1" t="inlineStr"><is><t>sima</t></is></c>'
        .'<c r="B1" t="inlineStr"><is><r><rPr><b/></rPr><t>Fél</t></r><r><t>kövér</t></r><r><rPr><i/></rPr><t xml:space="preserve"> szöveg</t></r></is></c>'
        .'<c r="C1" t="s"><v>0</v></c></row>'
        .'<row r="2" spans="1:3" x14ac:dyDescent="0.25">'
        .'<c r="A2" t="inlineStr"><is><t xml:space="preserve">  szóközök  </t></is></c>'
        .'<c r="B2" t="inlineStr"><is><t>első sor'."\n".'második sor</t></is></c>'
        .'<c r="C2" t="inlineStr"><is><t>&lt;&amp;&gt; &quot;idézet&quot;</t></is></c>'
        // Excel escapes a carriage return as _x000D_ and a literal "_x" sequence as _x005F_x.
        .'<c r="D2" t="s"><v>1</v></c></row>';

    write_excel_xlsx(FIXTURES.'/xlsx/inline-strings.xlsx', [['Munka1', '', 'A1:D2', $data]], $sst, 2, [], [0]);
}

function build_xlsx_no_r_attributes(): void
{
    // Some generators write neither row numbers nor cell references; position decides.
    $sheet = XML_DECL.'<worksheet xmlns="'.NS_MAIN.'"><sheetData>'
        .'<row><c t="s"><v>0</v></c><c t="s"><v>1</v></c><c t="s"><v>2</v></c></row>'
        .'<row><c><v>1</v></c><c/><c><v>3</v></c></row>'
        .'<row></row>'
        .'<row><c t="inlineStr"><is><t>d</t></is></c></row>'
        .'</sheetData></worksheet>';
    $workbook = XML_DECL.'<workbook xmlns="'.NS_MAIN.'" xmlns:r="'.NS_REL.'"><sheets><sheet name="Sheet1" sheetId="1" r:id="rId1"/></sheets></workbook>';

    write_zip(FIXTURES.'/xlsx/no-r-attributes.xlsx', [
        ['[Content_Types].xml', xlsx_content_types(['xl/worksheets/sheet1.xml'], true, false), false],
        ['_rels/.rels', xlsx_package_rels(false), false],
        ['xl/workbook.xml', $workbook, false],
        ['xl/_rels/workbook.xml.rels', xlsx_rels([
            ['rId1', 'worksheet', 'worksheets/sheet1.xml'],
            ['rId2', 'sharedStrings', 'sharedStrings.xml'],
            ['rId3', 'styles', 'styles.xml'],
        ]), false],
        ['xl/worksheets/sheet1.xml', $sheet, false],
        ['xl/sharedStrings.xml', xlsx_sst(['<t>a</t>', '<t>b</t>', '<t>c</t>'], 3), false],
        ['xl/styles.xml', xlsx_excel_styles([], [0]), false],
    ]);
}

function build_xlsx_libreoffice(): void
{
    // LibreOffice Calc: uppercase format codes, explicit s="0", <cols>, AppVersion in app.xml.
    $sst = ['<t xml:space="preserve">Név</t>', '<t xml:space="preserve">Kor</t>', '<t xml:space="preserve">Születés</t>',
        '<t xml:space="preserve">Aktív</t>', '<t xml:space="preserve">Anna</t>', '<t xml:space="preserve">Béla</t>'];
    $styles = XML_DECL.'<styleSheet xmlns="'.NS_MAIN.'">'
        .'<numFmts count="2"><numFmt numFmtId="164" formatCode="General"/><numFmt numFmtId="165" formatCode="YYYY\-MM\-DD"/></numFmts>'
        .'<fonts count="1"><font><sz val="10"/><name val="Arial"/><family val="2"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
        .'<borders count="1"><border diagonalUp="false" diagonalDown="false"><left/><right/><top/><bottom/><diagonal/></border></borders>'
        .'<cellStyleXfs count="1"><xf numFmtId="164" fontId="0" fillId="0" borderId="0" applyFont="true" applyBorder="true" applyAlignment="true" applyProtection="true"/></cellStyleXfs>'
        .'<cellXfs count="2">'
        .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false"/>'
        .'<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyFont="false" applyBorder="false" applyAlignment="false" applyProtection="false"/>'
        .'</cellXfs><cellStyles count="1"><cellStyle name="Default" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $sheet = XML_DECL.'<worksheet xmlns="'.NS_MAIN.'" xmlns:r="'.NS_REL.'">'
        .'<sheetPr filterMode="false"><pageSetUpPr fitToPage="false"/></sheetPr><dimension ref="A1:D3"/>'
        .'<sheetViews><sheetView showFormulas="false" showGridLines="true" tabSelected="true" zoomScale="100" workbookViewId="0"/></sheetViews>'
        .'<sheetFormatPr defaultColWidth="11.53515625" defaultRowHeight="12.8" zeroHeight="false" outlineLevelRow="0" outlineLevelCol="0"/>'
        .'<cols><col collapsed="false" customWidth="false" hidden="false" outlineLevel="0" max="1024" min="1" style="0" width="11.52"/></cols>'
        .'<sheetData>'
        .'<row r="1" customFormat="false" ht="12.8" hidden="false" customHeight="false" outlineLevel="0" collapsed="false">'
        .'<c r="A1" s="0" t="s"><v>0</v></c><c r="B1" s="0" t="s"><v>1</v></c><c r="C1" s="0" t="s"><v>2</v></c><c r="D1" s="0" t="s"><v>3</v></c></row>'
        .'<row r="2" customFormat="false" ht="12.8" hidden="false" customHeight="false" outlineLevel="0" collapsed="false">'
        .'<c r="A2" s="0" t="s"><v>4</v></c><c r="B2" s="0" t="n"><v>34</v></c><c r="C2" s="1" t="n"><v>33010</v></c><c r="D2" s="0" t="b"><v>1</v></c></row>'
        .'<row r="3" customFormat="false" ht="12.8" hidden="false" customHeight="false" outlineLevel="0" collapsed="false">'
        .'<c r="A3" s="0" t="s"><v>5</v></c><c r="B3" s="0" t="n"><v>27.5</v></c><c r="C3" s="1" t="n"><v>37228</v></c><c r="D3" s="0" t="b"><v>0</v></c></row>'
        .'</sheetData><printOptions headings="false" gridLines="false" gridLinesSet="true" horizontalCentered="false" verticalCentered="false"/>'
        .'<pageMargins left="0.7875" right="0.7875" top="1.05277777777778" bottom="1.05277777777778" header="0.7875" footer="0.7875"/></worksheet>';
    $workbook = XML_DECL.'<workbook xmlns="'.NS_MAIN.'" xmlns:r="'.NS_REL.'">'
        .'<fileVersion appName="Calc"/><workbookPr backupFile="false" showObjects="all" date1904="false"/><workbookProtection/>'
        .'<bookViews><workbookView showHorizontalScroll="true" showVerticalScroll="true" showSheetTabs="true" xWindow="0" yWindow="0" windowWidth="16384" windowHeight="8192" tabRatio="500" firstSheet="0" activeTab="0"/></bookViews>'
        .'<sheets><sheet name="Munkalap1" sheetId="1" state="visible" r:id="rId2"/></sheets><calcPr iterateCount="100" refMode="A1" iterate="false" iterateDelta="0.001"/></workbook>';

    write_zip(FIXTURES.'/xlsx/libreoffice.xlsx', [
        ['_rels/.rels', xlsx_package_rels(true), false],
        ['docProps/app.xml', xlsx_app_props('LibreOffice/7.6.4.1$Linux_X86_64 LibreOffice_project/e19e193f88cd6c0525a17fb7a176ed8e6a3e2aa1'), false],
        ['docProps/core.xml', xlsx_core_props(), false],
        ['xl/_rels/workbook.xml.rels', xlsx_rels([
            ['rId1', 'styles', 'styles.xml'],
            ['rId2', 'worksheet', 'worksheets/sheet1.xml'],
            ['rId3', 'sharedStrings', 'sharedStrings.xml'],
        ]), false],
        ['xl/workbook.xml', $workbook, false],
        ['xl/styles.xml', $styles, false],
        ['xl/worksheets/sheet1.xml', $sheet, false],
        ['xl/sharedStrings.xml', xlsx_sst($sst, 6), false],
        ['[Content_Types].xml', xlsx_content_types(['xl/worksheets/sheet1.xml'], true, true), false],
    ]);
}

function build_xlsx_google_sheets(): void
{
    // Google Sheets: no docProps at all, the worksheet comes last in the relationships part.
    $sst = ['<t>Név</t>', '<t>Kor</t>', '<t>Születés</t>', '<t>Aktív</t>', '<t>Anna</t>', '<t>Béla</t>'];
    $styles = XML_DECL.'<styleSheet xmlns="'.NS_MAIN.'" xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac" '
        .'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006">'
        .'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
        .'<fonts count="2"><font><sz val="10.0"/><color rgb="FF000000"/><name val="Arial"/><scheme val="minor"/></font>'
        .'<font><color theme="1"/><name val="Arial"/><scheme val="minor"/></font></fonts>'
        .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="lightGray"/></fill></fills>'
        .'<borders count="1"><border/></borders><cellStyleXfs count="1"><xf borderId="0" fillId="0" fontId="0" numFmtId="0" applyAlignment="1" applyFont="1"/></cellStyleXfs>'
        .'<cellXfs count="3"><xf borderId="0" fillId="0" fontId="0" numFmtId="0" xfId="0" applyAlignment="1" applyFont="1"><alignment readingOrder="0" shrinkToFit="0" vertical="bottom" wrapText="0"/></xf>'
        .'<xf borderId="0" fillId="0" fontId="1" numFmtId="0" xfId="0" applyAlignment="1" applyFont="1"><alignment readingOrder="0"/></xf>'
        .'<xf borderId="0" fillId="0" fontId="1" numFmtId="164" xfId="0" applyAlignment="1" applyFont="1" applyNumberFormat="1"><alignment readingOrder="0"/></xf></cellXfs>'
        .'<cellStyles count="1"><cellStyle xfId="0" name="Normal" builtinId="0"/></cellStyles><dxfs count="0"/></styleSheet>';
    $sheet = XML_DECL.'<worksheet xmlns:xm="http://schemas.microsoft.com/office/excel/2006/main" xmlns:x14ac="http://schemas.microsoft.com/office/spreadsheetml/2009/9/ac" '
        .'xmlns:x14="http://schemas.microsoft.com/office/spreadsheetml/2009/9/main" xmlns:mv="urn:schemas-microsoft-com:mac:vml" '
        .'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:mx="http://schemas.microsoft.com/office/mac/excel/2008/main" '
        .'xmlns:r="'.NS_REL.'" xmlns="'.NS_MAIN.'">'
        .'<sheetPr><outlinePr summaryBelow="0" summaryRight="0"/></sheetPr>'
        .'<sheetViews><sheetView workbookViewId="0"/></sheetViews><sheetFormatPr customHeight="1" defaultColWidth="12.63" defaultRowHeight="15.75"/>'
        .'<sheetData>'
        .'<row r="1"><c r="A1" s="1" t="s"><v>0</v></c><c r="B1" s="1" t="s"><v>1</v></c><c r="C1" s="1" t="s"><v>2</v></c><c r="D1" s="1" t="s"><v>3</v></c></row>'
        .'<row r="2"><c r="A2" s="1" t="s"><v>4</v></c><c r="B2" s="1"><v>34.0</v></c><c r="C2" s="2"><v>33010.0</v></c><c r="D2" s="1" t="b"><v>1</v></c></row>'
        .'<row r="3"><c r="A3" s="1" t="s"><v>5</v></c><c r="B3" s="1"><v>27.5</v></c><c r="C3" s="2"><v>37228.0</v></c><c r="D3" s="1" t="b"><v>0</v></c></row>'
        .'</sheetData><drawing r:id="rId1"/></worksheet>';
    $workbook = XML_DECL.'<workbook xmlns:xr="http://schemas.microsoft.com/office/spreadsheetml/2014/revision" '
        .'xmlns:xr6="http://schemas.microsoft.com/office/spreadsheetml/2016/revision6" '
        .'xmlns:xr10="http://schemas.microsoft.com/office/spreadsheetml/2016/revision10" '
        .'xmlns:xr2="http://schemas.microsoft.com/office/spreadsheetml/2015/revision2" '
        .'xmlns:mc="http://schemas.openxmlformats.org/markup-compatibility/2006" xmlns:r="'.NS_REL.'" xmlns="'.NS_MAIN.'">'
        .'<workbookPr/><sheets><sheet state="visible" name="Munkalap1" sheetId="1" r:id="rId4"/></sheets><definedNames/><calcPr/></workbook>';

    write_zip(FIXTURES.'/xlsx/google-sheets.xlsx', [
        ['xl/_rels/workbook.xml.rels', xlsx_rels([
            ['rId1', 'theme', 'theme/theme1.xml'],
            ['rId2', 'styles', 'styles.xml'],
            ['rId3', 'sharedStrings', 'sharedStrings.xml'],
            ['rId4', 'worksheet', 'worksheets/sheet1.xml'],
        ]), false],
        ['xl/worksheets/sheet1.xml', $sheet, false],
        ['xl/worksheets/_rels/sheet1.xml.rels', xlsx_rels([['rId1', 'drawing', '../drawings/drawing1.xml']]), false],
        ['xl/drawings/drawing1.xml', XML_DECL.'<xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"/>', false],
        ['xl/sharedStrings.xml', xlsx_sst($sst, 10), false],
        ['xl/theme/theme1.xml', xlsx_theme(), false],
        ['xl/styles.xml', $styles, false],
        ['xl/workbook.xml', $workbook, false],
        ['_rels/.rels', xlsx_package_rels(false), false],
        ['[Content_Types].xml', xlsx_content_types(['xl/worksheets/sheet1.xml'], true, false), false],
    ]);
}

const ODS_MIME = 'application/vnd.oasis.opendocument.spreadsheet';

/**
 * LibreOffice's content.xml envelope: the namespaces it declares, plus the automatic styles
 * (column widths, cell formats and, for hidden sheets, a table style with table:display="false").
 */
function ods_content(string $automaticStyles, string $tables): string
{
    return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
        .'xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0" '
        .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
        .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" '
        .'xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" '
        .'xmlns:number="urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0" '
        .'xmlns:of="urn:oasis:names:tc:opendocument:xmlns:of:1.2" office:version="1.3">'
        .'<office:scripts/><office:font-face-decls/>'
        .'<office:automatic-styles>'.$automaticStyles.'</office:automatic-styles>'
        .'<office:body><office:spreadsheet>'
        .'<table:calculation-settings table:automatic-find-labels="false"/>'
        .$tables
        .'<table:named-expressions/>'
        .'</office:spreadsheet></office:body></office:document-content>';
}

function write_ods(string $path, string $content): void
{
    $manifest = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
        .'<manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.3">'
        .'<manifest:file-entry manifest:full-path="/" manifest:version="1.3" manifest:media-type="'.ODS_MIME.'"/>'
        .'<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>'
        .'</manifest:manifest>';

    // The mimetype entry comes first and stays uncompressed so the file can be sniffed by magic bytes.
    write_zip($path, [
        ['mimetype', ODS_MIME, true],
        ['content.xml', $content, false],
        ['META-INF/manifest.xml', $manifest, false],
    ]);
}

/**
 * A table with the column declaration LibreOffice writes ahead of the rows.
 */
function ods_table(string $name, string $rows, string $style = 'ta1', int $columns = 1): string
{
    return '<table:table table:name="'.$name.'" table:style-name="'.$style.'">'
        .'<table:table-column table:style-name="co1" table:number-columns-repeated="'.$columns.'" table:default-cell-style-name="Default"/>'
        .$rows.'</table:table>';
}

function ods_text(string $value): string
{
    return '<table:table-cell office:value-type="string" calcext:value-type="string"><text:p>'.$value.'</text:p></table:table-cell>';
}

function ods_float(string $value): string
{
    return '<table:table-cell office:value-type="float" office:value="'.$value.'" calcext:value-type="float"><text:p>'.$value.'</text:p></table:table-cell>';
}

function build_ods_libreoffice_mixed(): void
{
    $styles = '<style:style style:name="co1" style:family="table-column"><style:table-column-properties fo:break-before="auto" style:column-width="2.258cm"/></style:style>'
        .'<style:style style:name="ta1" style:family="table" style:master-page-name="Default"><style:table-properties table:display="true" style:writing-mode="lr-tb"/></style:style>'
        .'<number:date-style style:name="N37"><number:year number:style="long"/><number:text>-</number:text><number:month number:style="long"/><number:text>-</number:text><number:day number:style="long"/></number:date-style>'
        .'<style:style style:name="ce1" style:family="table-cell" style:parent-style-name="Default" style:data-style-name="N37"/>';

    // The two trailing repeats are exactly what LibreOffice appends to every row and to the sheet.
    $trailingCells = '<table:table-cell table:number-columns-repeated="16377"/>';
    $trailingRows = '<table:table-row table:style-name="ro1" table:number-rows-repeated="1048559"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>';
    $datum = '<table:table-cell table:style-name="ce1" office:value-type="date" office:date-value="2025-01-31" calcext:value-type="date"><text:p>2025-01-31</text:p></table:table-cell>';

    $rows = '<table:table-header-rows><table:table-row table:style-name="ro1">'
        .ods_text('Egész').ods_text('Tört').ods_text('Szöveg').ods_text('Logikai').ods_text('Dátum').ods_text('Üres').ods_text('Utolsó')
        .$trailingCells.'</table:table-row></table:table-header-rows>'
        .'<table:table-row table:style-name="ro1">'
        .ods_float('42').ods_float('3.14').ods_text('szöveg')
        .'<table:table-cell office:value-type="boolean" office:boolean-value="true" calcext:value-type="boolean"><text:p>TRUE</text:p></table:table-cell>'
        .$datum
        .'<table:table-cell/>'
        .ods_text('utolsó')
        .$trailingCells.'</table:table-row>'
        .'<table:table-row table:style-name="ro1">'
        .'<table:table-cell office:value-type="percentage" office:value="0.125" calcext:value-type="percentage"><text:p>12.5%</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="currency" office:currency="HUF" office:value="1500" calcext:value-type="currency"><text:p>1 500 Ft</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="time" office:time-value="PT13H45M00S" calcext:value-type="time"><text:p>13:45:00</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="date" office:date-value="2025-01-31T13:45:00" calcext:value-type="date"><text:p>2025-01-31 13:45:00</text:p></table:table-cell>'
        .$trailingCells.'</table:table-row>'
        .'<table:table-row table:style-name="ro1">'
        .'<table:table-cell office:value-type="string" calcext:value-type="string"><text:p>sor1</text:p><text:p>sor2</text:p><text:p/><text:p>sor4</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="string" calcext:value-type="string"><text:p>a<text:s text:c="3"/>b<text:tab/>c<text:s/>d<text:line-break/>e</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="string" calcext:value-type="string"><text:p>Félkövér <text:span text:style-name="T1">és</text:span> dőlt &lt;&amp;&gt;</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="string" calcext:value-type="string"><office:annotation draw:style-name="gr1" svg:width="3cm" svg:height="1cm" xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0">'
        .'<dc:creator xmlns:dc="http://purl.org/dc/elements/1.1/">Szerző</dc:creator><text:p>megjegyzés</text:p></office:annotation><text:p>cella szöveg</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="float" office:value="7"><office:annotation xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0"><text:p>szám megjegyzés</text:p></office:annotation><text:p>7</text:p></table:table-cell>'
        .$trailingCells.'</table:table-row>'
        .'<table:table-row table:style-name="ro1">'
        .'<table:table-cell table:number-columns-spanned="2" table:number-rows-spanned="1" office:value-type="string" calcext:value-type="string"><text:p>összevont</text:p></table:table-cell>'
        .'<table:covered-table-cell/>'
        .ods_text('x')
        .'<table:table-cell office:value-type="float" office:value="1E+20" calcext:value-type="float"><text:p>1E+20</text:p></table:table-cell>'
        .'<table:table-cell office:value-type="string" office:string-value="képlet" calcext:value-type="string"><text:p>képlet</text:p></table:table-cell>'
        .$trailingCells.'</table:table-row>'
        .'<table:table-row table:style-name="ro1" table:number-rows-repeated="2"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
        .'<table:table-row table:style-name="ro1"><table:table-cell table:number-columns-repeated="2"/>'.ods_text('lent').'<table:table-cell table:number-columns-repeated="16381"/></table:table-row>'
        .$trailingRows;

    $content = ods_content($styles, ods_table('Munka1', $rows, 'ta1', 16384));
    $content = str_replace('xmlns:of=', 'xmlns:calcext="urn:org:documentfoundation:names:experimental:calc:xmlns:calcext:1.0" xmlns:of=', $content);

    write_ods(FIXTURES.'/ods/libreoffice-mixed.ods', $content);
}

function build_ods_repeated(): void
{
    $rows = '<table:table-row>'
        .'<table:table-cell office:value-type="float" office:value="1" table:number-columns-repeated="3"><text:p>1</text:p></table:table-cell>'
        .'<table:table-cell table:number-columns-repeated="2"/>'
        .ods_text('x')
        .'<table:table-cell table:number-columns-repeated="16378"/></table:table-row>'
        .'<table:table-row table:number-rows-repeated="3">'.ods_text('r').ods_float('2').'</table:table-row>'
        .'<table:table-row table:number-rows-repeated="5"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
        .'<table:table-row><table:table-cell table:number-columns-repeated="3"/>'.ods_text('end').'</table:table-row>'
        .'<table:table-row table:number-rows-repeated="1048567"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>';

    write_ods(FIXTURES.'/ods/repeated.ods', ods_content('', ods_table('Munka1', $rows)));
}

function build_ods_multi_sheet(): void
{
    $styles = '<style:style style:name="ta1" style:family="table"><style:table-properties table:display="true"/></style:style>'
        .'<style:style style:name="ta2" style:family="table"><style:table-properties table:display="false"/></style:style>';
    $row = static fn (string $text): string => '<table:table-row>'.ods_text($text).'</table:table-row>';

    write_ods(FIXTURES.'/ods/multi-sheet.ods', ods_content($styles,
        ods_table('Első', $row('első'))
        .ods_table('Rejtett', $row('rejtett'), 'ta2')
        .ods_table('Utolsó', $row('utolsó'))));
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (! is_dir(FIXTURES.'/detect')) {
        mkdir(FIXTURES.'/detect', 0777, true);
    }

    build_detect_xlsx();
    build_detect_ods();
    build_detect_csv();
    build_detect_xls();
    build_detect_word_doc();
    build_detect_numbers();

    if (! is_dir(FIXTURES.'/csv')) {
        mkdir(FIXTURES.'/csv', 0777, true);
    }

    build_csv_hu_excel_1250();
    build_csv_utf8_bom_comma();
    build_csv_utf16le_tab();
    build_csv_quoted_multiline();

    if (! is_dir(FIXTURES.'/xlsx')) {
        mkdir(FIXTURES.'/xlsx', 0777, true);
    }

    build_xlsx_excel_mixed();
    build_xlsx_excel_1904();
    build_xlsx_multi_sheet();
    build_xlsx_inline_strings();
    build_xlsx_no_r_attributes();
    build_xlsx_libreoffice();
    build_xlsx_google_sheets();

    if (! is_dir(FIXTURES.'/ods')) {
        mkdir(FIXTURES.'/ods', 0777, true);
    }

    build_ods_libreoffice_mixed();
    build_ods_repeated();
    build_ods_multi_sheet();

    if (! is_dir(FIXTURES.'/xls')) {
        mkdir(FIXTURES.'/xls', 0777, true);
    }

    build_xls_workbooks();

    echo 'Fixtures written to '.FIXTURES."\n";
}
