<?php

declare(strict_types=1);

namespace Greenlight\Execution;

/**
 * Contains selected runs and advisory failures from artifact maintenance.
 *
 * @internal
 */
final readonly class ArtifactMaintenanceReport
{
    /**
     * @param list<ArtifactMaintenanceItem> $items
     * @param list<non-empty-string> $warnings
     */
    public function __construct(
        public array $items = [],
        public array $warnings = [],
        public bool $dryRun = false,
    ) {}
}
