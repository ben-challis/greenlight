<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Decodes JSON for the `toMatchJson()` structure comparison.
 *
 * @internal
 */
final class JsonComparison
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Prefixes every JSON string for comparison. This keeps string equality
     * and prevents PHP from rejecting object keys that start with a null byte.
     * The original JSON is used for diagnostics.
     *
     * @throws \JsonException
     */
    public static function decode(string $json): mixed
    {
        $prefixed = '';
        $insideString = false;
        $offset = 0;
        $bytes = \strlen($json);

        while ($offset < $bytes) {
            $span = \strcspn($json, '"\\', $offset);
            $prefixed .= \substr($json, $offset, $span);
            $offset += $span;

            if ($offset === $bytes) {
                break;
            }

            if ($json[$offset] === '\\') {
                $prefixed .= \substr($json, $offset, 2);
                $offset += 2;
                continue;
            }

            $insideString = !$insideString;
            $prefixed .= $insideString ? '"_' : '"';
            ++$offset;
        }

        return \json_decode($prefixed, false, 512, \JSON_THROW_ON_ERROR);
    }
}
