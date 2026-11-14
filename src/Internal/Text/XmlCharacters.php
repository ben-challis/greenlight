<?php

declare(strict_types=1);

namespace Greenlight\Internal\Text;

/**
 * Makes text safe to place in an XML 1.0 document.
 *
 * scrub() replaces each invalid UTF-8 sequence and each character that XML 1.0
 * does not allow with U+FFFD. It does not escape markup characters.
 *
 * @internal
 */
final class XmlCharacters
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function scrub(string $value): string
    {
        return (string) \preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            "\u{FFFD}",
            Utf8::scrub($value),
        );
    }
}
