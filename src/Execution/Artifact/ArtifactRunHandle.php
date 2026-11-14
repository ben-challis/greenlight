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

    /** @var array<non-empty-string, array{bytes: int, sha256: non-empty-string, identity: string|null}> */
    private array $published = [];

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

    /**
     * Records the digest of a published file, so that completion does not
     * read the file again. Completion reuses the digest only when the size,
     * the inode, and the modification time of the file stay the same. The
     * modification time has a resolution of one second. A same-size rewrite in
     * the same second keeps the recorded digest, and a later prune then
     * rejects the run when it verifies the content.
     *
     * @param non-empty-string $sha256
     */
    public function recordPublished(string $relativePath, int $bytes, string $sha256): void
    {
        if ($relativePath === '') {
            return;
        }

        $path = $this->directory . '/' . $relativePath;
        $this->published[$relativePath] = [
            'bytes' => $bytes,
            'sha256' => $sha256,
            'identity' => $this->identity($path),
        ];
    }

    /** @throws \RuntimeException */
    public function complete(): void
    {
        if ($this->closed) {
            return;
        }

        $known = [];
        foreach ($this->published as $relativePath => $file) {
            $path = $this->directory . '/' . $relativePath;
            \clearstatcache(true, $path);
            if ($file['identity'] !== null && $this->identity($path) === $file['identity']) {
                $known[$relativePath] = ['bytes' => $file['bytes'], 'sha256' => $file['sha256']];
            }
        }
        $files = ArtifactRetention::contentManifest($this->directory, $known);
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

    private function identity(string $path): ?string
    {
        $stat = ErrorTrap::run(static fn() => \stat($path));

        return \is_array($stat) ? $stat['ino'] . ':' . $stat['mtime'] : null;
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
