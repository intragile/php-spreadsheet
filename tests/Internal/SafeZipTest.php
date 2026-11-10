<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal;

use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class SafeZipTest extends TestCase
{
    public function test_xml_reader_obeys_a_per_part_byte_cap(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.xml' => '<a>'.str_repeat('<b/>', 1000).'</a>']), 10, 1_000_000);

        try {
            $this->assertSame(1, $this->countElements($zip, 'a.xml', 5_000));

            $this->expectException(LimitExceeded::class);
            $this->expectExceptionMessage('declares 4007 bytes; the limit is 1000');

            $this->countElements($zip, 'a.xml', 1_000);
        } finally {
            $zip->close();
        }
    }

    private function countElements(SafeZip $zip, string $name, int $maxBytes): int
    {
        $xml = $zip->xmlReader($name, $maxBytes);
        $count = 0;

        try {
            while ($xml->read()) {
                $count += $xml->localName() === 'a' && $xml->nodeType() === \XMLReader::ELEMENT ? 1 : 0;
            }
        } finally {
            $xml->close();
        }

        return $count;
    }
}
