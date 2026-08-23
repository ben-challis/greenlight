<?php

declare(strict_types=1);

namespace Greenlight\Cli\Configuration;

/**
 * Contains command-line coverage changes.
 *
 * @internal
 */
final readonly class CoverageOverrides
{
    /**
     * @param list<non-empty-string> $includePaths
     * @param non-empty-string|null $perTestTarget
     */
    public function __construct(
        public array $includePaths = [],
        public ?string $perTestTarget = null,
        public bool $disabled = false,
    ) {}
}
