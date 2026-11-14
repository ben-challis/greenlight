<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Text;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Export\XmlEscaper;
use Greenlight\Internal\Text\Utf8;

use function Greenlight\expect;

final class XmlCharactersTest
{
    #[Test]
    #[DataSet('values')]
    public function bothXmlEscapersKeepTheirOutput(string $value, string $characters, string $attribute): void
    {
        expect(Utf8::xmlCharacters($value))
            ->because('the JUnit reporter text MUST not change')
            ->toBe($characters);
        expect(XmlEscaper::attribute($value))
            ->because('the coverage export attributes MUST not change')
            ->toBe($attribute);
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function values(): iterable
    {
        yield 'control characters' => [
            "a\x00b\x01c\x0Bd\x1Fe\x7Ff",
            "a\u{FFFD}b\u{FFFD}c\u{FFFD}d\u{FFFD}e\x7Ff",
            "a\u{FFFD}b\u{FFFD}c\u{FFFD}d\u{FFFD}e\x7Ff",
        ];
        yield 'allowed whitespace' => ["t\tn\nr\r", "t\tn\nr\r", 't&#x9;n&#xA;r&#xD;'];
        yield 'invalid UTF-8' => [
            "x\xC3y\xFF\xFEz\xED\xA0\x80",
            "x\u{FFFD}y\u{FFFD}\u{FFFD}z\u{FFFD}",
            "x\u{FFFD}y\u{FFFD}\u{FFFD}z\u{FFFD}",
        ];
        yield 'markup and CDATA end' => ['a]]>b<c>&"\'', 'a]]>b<c>&"\'', 'a]]&gt;b&lt;c&gt;&amp;&quot;&apos;'];
        yield 'noncharacters' => ["\u{FFFE}\u{FFFF}\u{10FFFF}", "\u{FFFD}\u{FFFD}\u{10FFFF}", "\u{FFFD}\u{FFFD}\u{10FFFF}"];
    }
}
