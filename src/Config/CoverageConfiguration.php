<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Resolved coverage settings. Collection itself lives elsewhere; this object
 * only records what the user asked for.
 *
 * @internal
 */
final readonly class CoverageConfiguration
{
    /**
     * @param list<non-empty-string> $includePaths
     * @param non-empty-string|null $driver
     * @param list<CoverageExport> $exports
     */
    public function __construct(
        public array $includePaths,
        public ?string $driver,
        public array $exports,
    ) {}
}
