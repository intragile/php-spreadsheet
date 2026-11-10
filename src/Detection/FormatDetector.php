<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Detection;

use IntrAgile\Spreadsheet\Csv\CsvSniffer;
use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\SpreadsheetException;
use IntrAgile\Spreadsheet\Exception\UnsupportedFormat;
use IntrAgile\Spreadsheet\Format;
use ZipArchive;

/**
 * Identifies a spreadsheet's format from its content, ignoring the file extension.
 *
 * Content wins because uploaded files are routinely misnamed.
 */
final class FormatDetector
{
    private const SNIFF_BYTES = 8192;

    private const ODS_MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet';

    private const ODS_TEMPLATE_MIMETYPE = 'application/vnd.oasis.opendocument.spreadsheet-template';

    private const MAX_CONTENT_TYPES_BYTES = 1_048_576;

    private const OLE2_SIGNATURE = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    /** How far into an OLE2 file to look for the stream names of an encrypted Office package. */
    private const OLE2_PROBE_BYTES = 65_536;

    /** Main part content type of a binary workbook (xlsb). */
    private const XLSB_MAIN_TYPE = 'application/vnd.ms-excel.sheet.binary.macroEnabled.main';

    /**
     * @throws UnsupportedFormat when the file is empty, or recognisable but not a supported spreadsheet
     * @throws CorruptFile when the file starts like a zip archive but cannot be opened as one
     * @throws SpreadsheetException when the file cannot be read
     */
    public static function detect(string $path): Format
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        $head = @file_get_contents($path, false, null, 0, self::SNIFF_BYTES);

        if ($head === false) {
            throw new SpreadsheetException("Cannot read \"{$path}\".");
        }

        // An empty upload is almost always a failed transfer; reading it as a CSV without rows would hide that.
        if ($head === '') {
            throw new UnsupportedFormat('Unsupported format: empty file.');
        }

        if (str_starts_with($head, self::OLE2_SIGNATURE)) {
            throw new UnsupportedFormat(self::isEncryptedOfficePackage($path)
                ? 'Unsupported format: password-protected Office file (an encrypted xlsx or similar); remove the password and try again.'
                : 'Unsupported format: legacy binary Excel (xls), a password-protected Office file, or another OLE2 document.');
        }

        if (str_starts_with($head, "PK\x03\x04") || str_starts_with($head, "PK\x05\x06")) {
            return self::detectZip($path);
        }

        if (str_contains($head, '%PDF-') && strpos($head, '%PDF-') < 1024) {
            throw new UnsupportedFormat('Unsupported format: pdf.');
        }

        if (self::looksLikeText($head)) {
            return Format::Csv;
        }

        throw new UnsupportedFormat('Unsupported format: unrecognised binary file.');
    }

    private static function detectZip(string $path): Format
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new CorruptFile('The file starts like a zip archive but cannot be opened; it may be truncated or damaged.');
        }

        try {
            if ($zip->locateName('mimetype') !== false) {
                $mimetype = trim((string) $zip->getFromName('mimetype', 128));

                if ($mimetype === self::ODS_MIMETYPE || $mimetype === self::ODS_TEMPLATE_MIMETYPE) {
                    return Format::Ods;
                }

                throw new UnsupportedFormat("Unsupported format: OpenDocument file of type \"{$mimetype}\".");
            }

            if ($zip->locateName('Index/Document.iwa') !== false || $zip->locateName('Index.zip') !== false) {
                throw new UnsupportedFormat('Unsupported format: Apple numbers.');
            }

            $contentTypes = $zip->locateName('[Content_Types].xml') !== false
                ? (string) $zip->getFromName('[Content_Types].xml', self::MAX_CONTENT_TYPES_BYTES)
                : '';

            if (str_contains($contentTypes, self::XLSB_MAIN_TYPE) || $zip->locateName('xl/workbook.bin') !== false) {
                throw new UnsupportedFormat('Unsupported format: binary Excel workbook (xlsb).');
            }

            if (str_contains($contentTypes, 'spreadsheetml') || $zip->locateName('xl/workbook.xml') !== false) {
                return Format::Xlsx;
            }

            if ($contentTypes !== '') {
                throw new UnsupportedFormat('Unsupported format: Office Open XML document that is not a spreadsheet.');
            }

            throw new UnsupportedFormat('Unsupported format: zip archive that is not a spreadsheet.');
        } finally {
            $zip->close();
        }
    }

    /**
     * An encrypted OOXML file is an OLE2 container holding "EncryptionInfo" and "EncryptedPackage"
     * streams. Directory entry names are UTF-16LE; the directory usually sits near the start of
     * the file, so a miss falls back to the generic OLE2 message rather than a wrong verdict.
     */
    private static function isEncryptedOfficePackage(string $path): bool
    {
        $probe = @file_get_contents($path, false, null, 0, self::OLE2_PROBE_BYTES);

        return is_string($probe) && str_contains($probe, mb_convert_encoding('EncryptedPackage', 'UTF-16LE', 'UTF-8'));
    }

    private static function looksLikeText(string $head): bool
    {
        if (str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF") || str_starts_with($head, "\x00\x00\xFE\xFF")) {
            return true;
        }

        return ! str_contains($head, "\0") || CsvSniffer::unicodeWithoutMark($head) !== null;
    }
}
