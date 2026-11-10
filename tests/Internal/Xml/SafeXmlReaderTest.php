<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal\Xml;

use IntrAgile\Spreadsheet\Internal\Xml\MalformedXml;
use IntrAgile\Spreadsheet\Internal\Xml\SafeXmlReader;
use IntrAgile\Spreadsheet\Tests\Support\Fixtures;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;
use XMLReader;

final class SafeXmlReaderTest extends TestCase
{
    protected function setUp(): void
    {
        if (! extension_loaded('xmlreader')) {
            $this->markTestSkipped('ext-xmlreader is required.');
        }
    }

    private static function drainText(SafeXmlReader $reader): string
    {
        $text = '';

        while ($reader->read()) {
            if (in_array($reader->nodeType(), [XMLReader::TEXT, XMLReader::CDATA], true)) {
                $text .= $reader->value();
            }
        }

        return $text;
    }

    public function test_reads_elements_attributes_and_namespaces(): void
    {
        $reader = SafeXmlReader::fromString('<w:doc xmlns:w="urn:w"><w:p w:id="7">hi</w:p><e/></w:doc>');
        $seen = [];

        while ($reader->read()) {
            if ($reader->nodeType() === XMLReader::ELEMENT) {
                $seen[] = [$reader->localName(), $reader->namespaceUri(), $reader->attribute('id', 'urn:w'), $reader->isEmptyElement(), $reader->depth()];
            }
        }

        $this->assertSame([
            ['doc', 'urn:w', null, false, 0],
            ['p', 'urn:w', '7', false, 1],
            ['e', '', null, true, 1],
        ], $seen);
    }

    public function test_doctype_is_refused_so_external_entities_never_resolve(): void
    {
        $secret = Fixtures::file('root:x:0:0:secret', 'txt');
        $xml = '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file://'.$secret.'">]><x>&e;</x>';
        $text = '';

        try {
            $text = self::drainText(SafeXmlReader::fromString($xml));
            $this->fail('A document with a DOCTYPE must be refused.');
        } catch (MalformedXml) {
            $this->assertStringNotContainsString('root:', $text);
        }
    }

    public function test_doctype_in_a_file_is_refused(): void
    {
        $path = Fixtures::file('<!DOCTYPE lolz [<!ENTITY lol "lol"><!ENTITY lol2 "&lol;&lol;&lol;">]><lolz>&lol2;</lolz>', 'xml');

        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromUri($path));
    }

    public function test_malformed_xml_throws_malformed_xml(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromString('<a><b></a>'));
    }

    public function test_empty_input_throws_malformed_xml(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromString(''));
    }

    public function test_cdata_and_predefined_entities(): void
    {
        $this->assertSame('a&b<c>', self::drainText(SafeXmlReader::fromString('<r>a&amp;b<![CDATA[<c>]]></r>')));
    }

    public function test_undeclared_namespace_prefix_is_not_fatal(): void
    {
        $text = self::drainText(SafeXmlReader::fromString('<r xmlns:w="urn:w"><w:t>before</w:t><foo:bar>inside</foo:bar><w:t>after</w:t></r>'));

        $this->assertSame('beforeinsideafter', $text);
    }

    public function test_fatal_error_after_recoverable_errors_is_still_detected(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromString('<r>'.str_repeat('<foo:bar/>', 5000).'<x></r>'));
    }

    public function test_recoverable_errors_do_not_accumulate_in_memory(): void
    {
        $path = Fixtures::file('<r><t>hello</t>'.str_repeat('<foo:bar/>', 1_000_000).'</r>', 'xml');

        $growth = self::peakGrowth(static function () use ($path): void {
            self::assertSame('hello', self::drainText(SafeXmlReader::fromUri($path)));
        });

        $this->assertLessThan(16 * 1024 * 1024, $growth);
    }

    public function test_skipping_a_subtree_full_of_recoverable_errors_stays_bounded(): void
    {
        $path = Fixtures::file('<r><skip>'.str_repeat('<foo:bar/>', 1_000_000).'</skip><t>after</t></r>', 'xml');

        $growth = self::peakGrowth(function () use ($path): void {
            $reader = SafeXmlReader::fromUri($path);
            $text = '';
            $moved = $reader->read();

            while ($moved) {
                if ($reader->nodeType() === XMLReader::ELEMENT && $reader->localName() === 'skip') {
                    $moved = $reader->next();

                    continue;
                }

                if ($reader->nodeType() === XMLReader::TEXT) {
                    $text .= $reader->value();
                }

                $moved = $reader->read();
            }

            $this->assertSame('after', $text);
        });

        $this->assertLessThan(16 * 1024 * 1024, $growth);
    }

    public function test_next_moves_past_the_subtree_like_xmlreader(): void
    {
        $reader = SafeXmlReader::fromString('<r><a><b>x</b><c/></a><d>y</d><e/></r>');
        $seen = [];
        $moved = $reader->read();

        while ($moved) {
            $seen[] = $reader->nodeType().':'.$reader->localName();
            $moved = in_array($reader->localName(), ['a', 'e'], true) && $reader->nodeType() === XMLReader::ELEMENT
                ? $reader->next()
                : $reader->read();
        }

        $this->assertSame(['1:r', '1:a', '1:d', '3:#text', '15:d', '1:e', '15:r'], $seen);
    }

    public function test_doctype_without_internal_subset_is_accepted(): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">'."\n"
            .'<plist version="1.0"><dict><key>Name</key><string>Győr</string></dict></plist>';

        $this->assertSame('NameGyőr', self::drainText(SafeXmlReader::fromString($xml)));
    }

    public function test_external_dtd_is_never_loaded(): void
    {
        $dtd = Fixtures::file('<!ENTITY secret "TOP-SECRET-FROM-DTD"><!ATTLIST r leak CDATA "TOP-SECRET-ATTR">', 'dtd');
        $xml = '<?xml version="1.0"?><!DOCTYPE r SYSTEM "file://'.$dtd.'"><r>a &secret; b</r>';
        $reader = SafeXmlReader::fromString($xml);
        $text = '';
        $attribute = null;

        while ($reader->read()) {
            if ($reader->nodeType() === XMLReader::ELEMENT) {
                $attribute ??= $reader->attribute('leak');
            } elseif (in_array($reader->nodeType(), [XMLReader::TEXT, XMLReader::CDATA, XMLReader::ENTITY_REF], true)) {
                $text .= $reader->value();
            }
        }

        $this->assertStringNotContainsString('TOP-SECRET', $text);
        $this->assertNull($attribute);
    }

    public function test_external_system_entity_in_internal_subset_is_refused(): void
    {
        $secret = Fixtures::file('root:x:0:0:secret', 'txt');
        $path = Fixtures::file('<?xml version="1.0"?><!DOCTYPE r [<!ENTITY % ext SYSTEM "file://'.$secret.'"> %ext;]><r>x</r>', 'xml');
        $text = '';

        try {
            $text = self::drainText(SafeXmlReader::fromUri($path));
            $this->fail('A document with an internal subset must be refused.');
        } catch (MalformedXml $exception) {
            $this->assertStringNotContainsString('root:', $text.$exception->getMessage());
        }
    }

    public function test_billion_laughs_internal_subset_is_refused_quickly(): void
    {
        $subset = '<!ENTITY lol0 "lol">';

        for ($i = 1; $i <= 9; $i++) {
            $subset .= '<!ENTITY lol'.$i.' "'.str_repeat('&lol'.($i - 1).';', 10).'">';
        }

        $path = Fixtures::file('<?xml version="1.0"?><!DOCTYPE lolz ['.$subset.']><lolz>&lol9;</lolz>', 'xml');
        $started = microtime(true);

        try {
            self::drainText(SafeXmlReader::fromUri($path));
            $this->fail('A document with an internal subset must be refused.');
        } catch (MalformedXml) {
            $this->assertLessThan(1.0, microtime(true) - $started);
        }
    }

    public function test_empty_internal_subset_brackets_are_refused(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromString('<!DOCTYPE r [<!-- nothing -->]><r>x</r>'));
    }

    public function test_from_file_does_not_unescape_percent_sequences(): void
    {
        $directory = sys_get_temp_dir().'/sps_pct_'.bin2hex(random_bytes(4));
        mkdir($directory);

        try {
            file_put_contents($directory.'/reportA.xml', '<r>wrong file</r>');
            file_put_contents($directory.'/report%41.xml', '<r>right file</r>');
            file_put_contents($directory.'/100% #1 ?.xml', '<r>hundred</r>');

            $this->assertSame('right file', self::drainText(SafeXmlReader::fromFile($directory.'/report%41.xml')));
            $this->assertSame('hundred', self::drainText(SafeXmlReader::fromFile($directory.'/100% #1 ?.xml')));
        } finally {
            foreach ((array) glob($directory.'/*') as $file) {
                @unlink((string) $file);
            }

            @rmdir($directory);
        }
    }

    public function test_from_file_never_interprets_stream_wrappers(): void
    {
        $this->expectException(MalformedXml::class);

        self::drainText(SafeXmlReader::fromFile('data://text/plain,<r>injected</r>'));
    }

    public function test_error_mode_survives_readers_closing_out_of_order(): void
    {
        $before = libxml_use_internal_errors(false);

        try {
            $first = SafeXmlReader::fromString('<a><b/></a>');
            // Long enough that libxml meets the truncation only after the first steps.
            $second = SafeXmlReader::fromString('<a>'.str_repeat('<b>x</b>', 20_000).'<b>');
            $first->read();
            $second->read();

            $first->close();
            // The second reader still collects errors instead of emitting warnings.
            $this->assertTrue(libxml_use_internal_errors());

            try {
                self::drainText($second);
                $this->fail('A truncated document must throw.');
            } catch (MalformedXml) {
                // expected
            }

            $this->assertFalse(libxml_use_internal_errors());
        } finally {
            libxml_use_internal_errors($before);
        }
    }

    public function test_collected_fatal_error_belongs_to_its_reader(): void
    {
        $good = SafeXmlReader::fromString('<a><b>1</b><b>2</b></a>');
        $bad = SafeXmlReader::fromString('<a>'.str_repeat('<b>x</b>', 20_000).'<b>');
        $good->read();

        $raw = $bad->raw();
        $bad->read();

        while ($raw->read()) {
            // Walk until the fatal error stops the raw reader.
        }

        $this->assertTrue($bad->collectErrors());
        $this->assertFalse($good->collectErrors());
        $this->assertSame('12', self::drainText($good));

        $this->expectException(MalformedXml::class);

        $bad->read();
    }

    public function test_closing_a_reader_keeps_the_errors_another_open_reader_has_not_collected(): void
    {
        $other = SafeXmlReader::fromString('<a><b>1</b></a>');
        $other->read();
        $bad = SafeXmlReader::fromString('<a>'.str_repeat('<b>x</b>', 20_000).'<b>');
        $raw = $bad->raw();
        $bad->read();

        while ($raw->read()) {
            // Walk until the fatal error stops the raw reader, leaving it uncollected.
        }

        // Another reader closes while the fatal error is still pending.
        $other->close();

        $this->assertTrue($bad->collectErrors());

        $this->expectException(MalformedXml::class);

        $bad->read();
    }

    public function test_internal_error_state_is_restored(): void
    {
        $before = libxml_use_internal_errors(false);

        try {
            self::drainText(SafeXmlReader::fromString('<r>ok</r>'));
            $this->assertFalse(libxml_use_internal_errors(false));
        } finally {
            libxml_use_internal_errors($before);
        }
    }
}
