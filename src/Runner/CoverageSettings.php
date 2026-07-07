<?php

declare(strict_types=1);

namespace Greenlight\Runner;

/**
 * What the runner needs to collect coverage: absolute include paths and an
 * optional driver preference. Null settings mean coverage is off.
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
