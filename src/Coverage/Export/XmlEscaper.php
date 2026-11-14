<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Export;

use Greenlight\Internal\Text\XmlCharacters;

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
        $value = \htmlspecialchars(XmlCharacters::scrub($value), \ENT_XML1 | \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

        return \str_replace(
            ["\t", "\n", "\r"],
            ['&#x9;', '&#xA;', '&#xD;'],
            $value,
        );
    }
}
