<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Pluralises nouns for counted report fragments: "1 test", "2 tests".
 *
 * count() appends "s" unless an irregular plural is given.
 *
 * @internal
 */
final class Plural
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function count(int $count, string $noun, ?string $plural = null): string
    {
        return \sprintf('%d %s', $count, $count === 1 ? $noun : $plural ?? $noun . 's');
    }
}
