<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection;

/**
 * Contains absolute include and exclude paths and an optional preference for a
 * coverage driver.
 *
 * Null settings disable coverage.
 *
 * @internal
 */
final readonly class CoverageSettings
{
    /**
     * @param list<non-empty-string> $includePaths Absolute paths.
     * @param non-empty-string|null $driver The value is 'pcov' or 'xdebug'. Null tries both.
     * @param list<non-empty-string> $excludePaths Absolute paths.
     */
    public function __construct(
        public array $includePaths,
        public ?string $driver = null,
        public array $excludePaths = [],
    ) {}
}
