<?php

declare(strict_types=1);

namespace Greenlight\Execution;

/**
 * Describes one completed artifact run that maintenance selects.
 *
 * @internal
 */
final readonly class ArtifactMaintenanceItem
{
    /**
     * @param non-empty-string $directory
     * @param non-empty-list<'age'|'count'|'size'> $reasons
     */
    public function __construct(
        public string $directory,
        public int $bytes,
        public array $reasons,
    ) {}
}
