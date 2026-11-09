<?php

declare(strict_types=1);

namespace IntrAgile\Spreadsheet\Tests\Internal\Xml;

use IntrAgile\Spreadsheet\Internal\Xml\XmlEscaper;
use IntrAgile\Spreadsheet\Tests\Support\TestCase;

final class XmlEscaperTest extends TestCase
{
    public function test_escaper_strips_control_chars(): void
    {
        $this->assertSame('ab&lt;', XmlEscaper::text("a\x01b<"));
    }

    public function test_escapes_all_special_characters_and_keeps_whitespace_and_unicode(): void
    {
        $this->assertSame('&amp;&lt;&gt;&quot;&apos;', XmlEscaper::text('&<>"\''));
        $this->assertSame("a\tb\nc\rd \u{1F600} \u{E1}", XmlEscaper::text("a\tb\nc\rd \u{1F600} \u{E1}"));
        $this->assertSame('ab', XmlEscaper::text("a\u{FFFE}\u{FFFF}b"));
    }

    public function test_invalid_utf8_is_dropped(): void
    {
        $this->assertSame('ab', XmlEscaper::text("a\xFFb"));
    }
}
