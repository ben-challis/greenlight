<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

/**
 * Describes one completed artifact run that a retention operation selects.
 *
 * @internal
 */
final readonly class ArtifactPruneItem
{
    /**
     * @param non-empty-string $runId
     * @param non-empty-string $directory
     * @param non-empty-list<'age'|'count'|'size'> $reasons
     */
    public function __construct(
        public string $runId,
        public string $directory,
        public int $bytes,
        public array $reasons,
    ) {}
}
