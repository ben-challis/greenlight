<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * A single requested coverage export: a report format and where to write it.
 *
 * @internal
 */
final readonly class CoverageExport
{
    /**
     * @param non-empty-string $format
     * @param non-empty-string $target
     */
    public function __construct(public string $format, public string $target) {}
}
