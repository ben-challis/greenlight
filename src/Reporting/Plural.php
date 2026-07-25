<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/** @internal */
final class Plural
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function count(int $count, string $noun, ?string $plural = null): string
    {
        return \sprintf('%d %s', $count, $count === 1 ? $noun : $plural ?? $noun . 's');
    }
}
