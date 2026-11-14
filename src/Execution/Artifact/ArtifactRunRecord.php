<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

/**
 * Contains validated ownership and completion metadata for one artifact run.
 *
 * An active record describes a run that did not complete. Its manifest is
 * empty, and its completion time is its start time.
 *
 * @internal
 */
final readonly class ArtifactRunRecord
{
    /**
     * @param non-empty-string $runId
     * @param array<non-empty-string, array{bytes: int, sha256: non-empty-string}> $files
     */
    public function __construct(
        public string $runId,
        public int $startedAt,
        public int $completedAt,
        public array $files,
        public int $bytes,
        public bool $active = false,
    ) {}
}
