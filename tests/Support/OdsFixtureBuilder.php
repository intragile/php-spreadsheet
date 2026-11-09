<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Support;

use DateTimeInterface;
use RuntimeException;
use ZipArchive;

/**
 * Streams large single-sheet ODS files to disk in LibreOffice's layout. Rows go straight to a
 * temporary content.xml, so building a file of any size takes constant memory.
 */
final class OdsFixtureBuilder
{
    /** @var resource */
    private $content;

    private string $contentPath;

    private string $buffer = '';

    public function __construct(private readonly string $path)
    {
        $this->contentPath = Fixtures::path('xml');
        $handle = fopen($this->contentPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException('Cannot create '.$this->contentPath);
        }

        $this->content = $handle;
        fwrite($this->content, '<?xml version="1.0" encoding="UTF-8"?>'
            .'<office:document-content xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0" '
            .'xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0" '
            .'xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" office:version="1.3">'
            .'<office:body><office:spreadsheet><table:table table:name="Sheet1">');
    }

    /**
     * @param  list<int|float|string|bool|DateTimeInterface|null>  $cells
     */
    public function addRow(array $cells): self
    {
        $xml = '<table:table-row>';

        foreach ($cells as $value) {
            $xml .= match (true) {
                $value === null => '<table:table-cell/>',
                is_bool($value) => '<table:table-cell office:value-type="boolean" office:boolean-value="'.($value ? 'true' : 'false').'"><text:p>'.($value ? 'TRUE' : 'FALSE').'</text:p></table:table-cell>',
                is_int($value), is_float($value) => '<table:table-cell office:value-type="float" office:value="'.$value.'"><text:p>'.$value.'</text:p></table:table-cell>',
                $value instanceof DateTimeInterface => '<table:table-cell office:value-type="date" office:date-value="'.$value->format('Y-m-d').'"><text:p>'.$value->format('Y-m-d').'</text:p></table:table-cell>',
                default => '<table:table-cell office:value-type="string"><text:p>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES).'</text:p></table:table-cell>',
            };
        }

        $this->buffer .= $xml.'</table:table-row>';

        if (strlen($this->buffer) > 65536) {
            fwrite($this->content, $this->buffer);
            $this->buffer = '';
        }

        return $this;
    }

    public function close(): string
    {
        fwrite($this->content, $this->buffer.'</table:table></office:spreadsheet></office:body></office:document-content>');
        fclose($this->content);

        $zip = new ZipArchive;

        if ($zip->open($this->path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create '.$this->path);
        }

        // The mimetype entry comes first and stays uncompressed, as the format requires.
        $zip->addFromString('mimetype', 'application/vnd.oasis.opendocument.spreadsheet');
        $zip->setCompressionName('mimetype', ZipArchive::CM_STORE);
        $zip->addFile($this->contentPath, 'content.xml');
        $zip->setCompressionName('content.xml', ZipArchive::CM_DEFLATE, 1);

        if (! $zip->close()) {
            throw new RuntimeException('Cannot write '.$this->path);
        }

        return $this->path;
    }
}
