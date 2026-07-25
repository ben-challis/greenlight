<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** @internal */
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
