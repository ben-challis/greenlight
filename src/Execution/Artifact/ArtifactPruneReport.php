<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

/**
 * Contains selected runs and advisory failures from one retention operation.
 *
 * @internal
 */
final readonly class ArtifactPruneReport
{
    /**
     * @param list<ArtifactPruneItem> $items
     * @param list<non-empty-string> $warnings
     */
    public function __construct(
        public array $items = [],
        public array $warnings = [],
        public bool $dryRun = false,
    ) {}
}
