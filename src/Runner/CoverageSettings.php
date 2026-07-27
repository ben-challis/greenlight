<?php

declare(strict_types=1);

namespace Greenlight\Runner;

/**
 * Contains absolute include paths and an optional preference for a coverage driver.
 *
 * Null settings disable coverage.
 *
 * @internal
 */
final readonly class CoverageSettings
{
    /**
     * @param list<non-empty-string> $includePaths absolute
     * @param non-empty-string|null $driver 'pcov' or 'xdebug'; null tries both
     */
    public function __construct(
        public array $includePaths,
        public ?string $driver = null,
    ) {}
}
