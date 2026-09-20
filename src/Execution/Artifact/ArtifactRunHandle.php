<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Holds the lifecycle lock and completes metadata for one owned run directory.
 *
 * @internal
 */
final class ArtifactRunHandle
{
    private bool $closed = false;

    /**
     * @param non-empty-string $runId
     * @param non-empty-string $directory
     * @param resource $lock
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $directory,
        private readonly mixed $lock,
    ) {}

    /** @throws \RuntimeException */
    public function complete(): void
    {
        if ($this->closed) {
            return;
        }

        $files = ArtifactRetention::contentManifest($this->directory);
        ArtifactRetention::writeMetadata($this->directory, [
            'version' => ArtifactRetention::METADATA_VERSION,
            'owner' => ArtifactRetention::OWNER,
            'runId' => $this->runId,
            'state' => 'completed',
            'startedAt' => ArtifactRetention::startedAt($this->directory, $this->runId),
            'completedAt' => \time(),
            'files' => $files,
        ]);
        $this->close();
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        ErrorTrap::run(fn() => \flock($this->lock, \LOCK_UN));
        ErrorTrap::run(fn() => \fclose($this->lock));
    }

    public function __destruct()
    {
        $this->close();
    }
}
