<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal\Zip;

use IntrAgile\Spreadsheet\Exception\CorruptFile;
use IntrAgile\Spreadsheet\Exception\LimitExceeded;
use IntrAgile\Spreadsheet\Internal\Xml\MalformedXml;
use IntrAgile\Spreadsheet\Internal\Zip\SafeZip;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use XMLReader;
use ZipArchive;

final class SafeZipTest extends TestCase
{
    private const ENTRIES = 5_000;

    private const BYTES = 268_435_456;

    public function test_reads_entries_and_lists_names(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => 'alpha', 'dir/b.xml' => '<b>beta</b>']), self::ENTRIES, self::BYTES);

        $this->assertTrue($zip->has('a.txt'));
        $this->assertFalse($zip->has('missing.txt'));
        $this->assertSame(['a.txt', 'dir/b.xml'], $zip->names());
        $this->assertSame('alpha', $zip->read('a.txt', 1024));

        $zip->close();
    }

    public function test_read_refuses_more_than_the_requested_bytes(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => str_repeat('x', 5000)]), self::ENTRIES, self::BYTES);

        $this->expectException(LimitExceeded::class);

        $zip->read('a.txt', 100);
    }

    public function test_declared_size_over_limit_throws_limit_exceeded(): void
    {
        $this->expectException(LimitExceeded::class);

        SafeZip::open(Fixtures::zip(['a.txt' => str_repeat('x', 5000)]), self::ENTRIES, 1000);
    }

    public function test_a_high_compression_ratio_entry_throws_limit_exceeded(): void
    {
        $path = Fixtures::zip(['bomb.xml' => str_repeat("\0", 50_000_000)]);

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessage('bomb.xml');

        SafeZip::open($path, self::ENTRIES, self::BYTES, 200);
    }

    public function test_a_ratio_within_the_limit_passes(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => str_repeat('hello world ', 2000)]), self::ENTRIES, self::BYTES, 200);

        $this->assertTrue($zip->has('a.txt'));
        $zip->close();
    }

    public function test_an_entry_hiding_its_real_size_is_stopped_while_reading(): void
    {
        $path = Fixtures::zipWithFalseSize('big.txt', str_repeat('A', 5_000_000), 1000);
        $zip = SafeZip::open($path, self::ENTRIES, 1_000_000);

        $this->expectException(LimitExceeded::class);

        $zip->read('big.txt', 10_000_000);
    }

    public function test_an_entry_inflating_beyond_its_declared_size_is_stopped_at_the_declared_cap(): void
    {
        $path = Fixtures::zipWithFalseSize('big.txt', str_repeat('A', 5_000_000), 1000);
        $zip = SafeZip::open($path, self::ENTRIES, self::BYTES);

        $this->expectException(LimitExceeded::class);
        $this->expectExceptionMessage('big.txt');

        $zip->read('big.txt', self::BYTES);
    }

    public function test_an_xml_entry_hiding_its_real_size_is_stopped_while_streaming(): void
    {
        $xml = '<root>'.str_repeat('<a>text</a>', 500_000).'</root>';
        $path = Fixtures::zipWithFalseSize('big.xml', $xml, 1000);
        $reader = SafeZip::open($path, self::ENTRIES, 1_000_000)->xmlReader('big.xml');

        $this->expectException(LimitExceeded::class);

        while ($reader->read()) {
            // Drain until the budget trips.
        }
    }

    public function test_the_total_budget_spans_entries(): void
    {
        $zip = SafeZip::open(
            Fixtures::zip(['a.txt' => str_repeat('a', 600), 'b.txt' => str_repeat('b', 600)]),
            self::ENTRIES,
            1000,
        );

        $this->assertSame(600, strlen($zip->read('a.txt', 10_000)));

        $this->expectException(LimitExceeded::class);

        $zip->read('b.txt', 10_000);
    }

    public function test_rejects_too_many_entries(): void
    {
        $entries = [];

        for ($i = 0; $i < 11; $i++) {
            $entries["f{$i}.txt"] = 'x';
        }

        $this->expectException(LimitExceeded::class);

        SafeZip::open(Fixtures::zip($entries), 10, self::BYTES);
    }

    public function test_refuses_path_traversal_names(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['../evil.txt' => 'x', '/abs.txt' => 'y', 'ok.txt' => 'z']), self::ENTRIES, self::BYTES);

        $this->assertFalse($zip->has('../evil.txt'));
        $this->assertFalse($zip->has('/abs.txt'));
        $this->assertSame(['ok.txt'], $zip->names());

        $this->expectException(LimitExceeded::class);

        $zip->read('../evil.txt', 100);
    }

    public function test_a_file_that_is_not_a_zip_is_rejected(): void
    {
        $this->expectException(CorruptFile::class);

        SafeZip::open(Fixtures::file('not a zip at all', 'zip'), self::ENTRIES, self::BYTES);
    }

    public function test_encrypted_entry_throws_corrupt_file(): void
    {
        $path = Fixtures::path('zip');
        $archive = new ZipArchive;
        $this->assertTrue($archive->open($path, ZipArchive::CREATE));
        $archive->addFromString('secret.xml', '<r/>');
        $archive->setEncryptionName('secret.xml', ZipArchive::EM_AES_256, 'x');
        $archive->close();

        $this->expectException(CorruptFile::class);

        SafeZip::open($path, self::ENTRIES, self::BYTES);
    }

    public function test_reading_a_missing_entry_is_rejected(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['a.txt' => 'x']), self::ENTRIES, self::BYTES);

        $this->expectException(CorruptFile::class);

        $zip->read('b.txt', 100);
    }

    public function test_streams_an_xml_entry(): void
    {
        $zip = SafeZip::open(Fixtures::zip(['doc.xml' => '<?xml version="1.0"?><r><t>hello</t><t>world</t></r>']), self::ENTRIES, self::BYTES);
        $reader = $zip->xmlReader('doc.xml');
        $texts = [];

        while ($reader->read()) {
            if ($reader->nodeType() === XMLReader::TEXT) {
                $texts[] = $reader->value();
            }
        }

        $reader->close();
        $this->assertSame(['hello', 'world'], $texts);
    }

    public function test_malformed_xml_entry_throws_malformed_xml(): void
    {
        $reader = SafeZip::open(Fixtures::zip(['doc.xml' => '<r><t>oops</r>']), self::ENTRIES, self::BYTES)->xmlReader('doc.xml');

        $this->expectException(MalformedXml::class);

        while ($reader->read()) {
            // Drain.
        }
    }
}
