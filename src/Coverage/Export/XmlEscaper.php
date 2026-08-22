<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Wire\Utf8;

/**
 * Escapes file-system paths for XML 1.0 attributes.
 *
 * @internal
 */
final class XmlEscaper
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function attribute(string $value): string
    {
        $value = (string) \preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            "\u{FFFD}",
            Utf8::scrub($value),
        );

        $value = \htmlspecialchars($value, \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return \str_replace(
            ["\t", "\n", "\r"],
            ['&#x9;', '&#xA;', '&#xD;'],
            $value,
        );
    }
}
