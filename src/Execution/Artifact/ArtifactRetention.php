<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Internal\Filesystem\DirectoryWalkError;
use Greenlight\Internal\Filesystem\DirectoryWalkFailure;
use Greenlight\Internal\Filesystem\SafeDirectory;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Resolves one artifact parent, starts owned runs below it, and applies
 * retention.
 *
 * The forConfiguration() method resolves the parent. The validateParent()
 * method checks it without a change. The begin() method creates and locks a
 * run directory. The prune() method delegates to RunPruner. The static
 * methods delegate to RunManifest.
 *
 * @internal
 */
final readonly class ArtifactRetention
{
    public const int METADATA_VERSION = RunManifest::VERSION;
    public const string OWNER = RunManifest::OWNER;
    public const string METADATA_FILE = RunManifest::METADATA_FILE;
    public const string LOCK_FILE = RunManifest::LOCK_FILE;

    /** @param non-empty-string $parent */
    private function __construct(
        private ArtifactConfiguration $configuration,
        public string $parent,
    ) {}

    /**
     * Resolves the artifact parent directory.
     *
     * The method removes empty and `.` segments. It resolves the deepest
     * existing ancestor of the parent with `realpath()`. Thus, a symbolic link
     * above the parent is valid. A symbolic link at or below the parent stays
     * invalid.
     *
     * @throws AttachmentError
     */
    public static function forConfiguration(ArtifactConfiguration $configuration, string $workingDirectory): self
    {
        $configured = $configuration->directory;
        if ($configured === '' || \str_contains($configured, "\0")) {
            throw AttachmentError::storage('Attachment output directory is invalid');
        }
        $absolute = \str_starts_with($configured, '/');
        $segments = \array_values(\array_filter(
            \explode('/', $configured),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.',
        ));
        if (!$absolute && \in_array('..', $segments, true)) {
            throw AttachmentError::storage('Keep a relative attachment output directory inside the working directory');
        }

        $base = '';
        if (!$absolute) {
            $workingReal = ErrorTrap::run(static fn() => \realpath($workingDirectory));
            $base = \rtrim($workingReal === false ? $workingDirectory : $workingReal, '/');
        }
        if ($segments === []) {
            // Retention scans and prunes the parent, so the working directory
            // and the filesystem root cannot be the parent.
            throw AttachmentError::storage('Attachment output directory is invalid');
        }

        return new self($configuration, self::resolvedParent($base, $segments));
    }

    /**
     * @param non-empty-list<string> $segments
     *
     * @return non-empty-string
     * @throws AttachmentError
     */
    private static function resolvedParent(string $base, array $segments): string
    {
        for ($depth = \count($segments) - 1; $depth >= 0; --$depth) {
            $ancestor = $base . '/' . \implode('/', \array_slice($segments, 0, $depth));
            if (!ErrorTrap::run(static fn() => \is_dir($ancestor))) {
                continue;
            }
            $real = ErrorTrap::run(static fn() => \realpath($ancestor));
            $missing = \array_slice($segments, $depth);
            if ($real === false || \in_array('..', $missing, true)) {
                break;
            }

            return \rtrim($real, '/') . '/' . \implode('/', $missing);
        }
        if (\in_array('..', $segments, true)) {
            throw AttachmentError::storage('Artifact parent directory is not canonical');
        }

        return $base . '/' . \implode('/', $segments);
    }

    /**
     * Rejects an unsafe artifact parent without a change to the filesystem.
     *
     * @throws AttachmentError
     */
    public function validateParent(): void
    {
        try {
            SafeDirectory::check($this->parent);
        } catch (DirectoryWalkError $error) {
            // Restricted filesystem access can hide every ancestor. Creation
            // reports the failure if access stays restricted.
            if ($error->failure !== DirectoryWalkFailure::NoExistingAncestor) {
                throw OutputDirectoryErrors::from($error);
            }
        }
        if (ErrorTrap::run(fn() => \is_dir($this->parent))
            && ErrorTrap::run(fn() => \realpath($this->parent)) !== $this->parent
        ) {
            throw AttachmentError::storage('Artifact parent directory is not canonical');
        }
    }

    /** @return non-empty-string */
    public function runDirectory(string $runId): string
    {
        return $this->parent . '/' . $runId;
    }

    /** @throws AttachmentError */
    public function begin(string $runId): ArtifactRunHandle
    {
        $validatedRunId = RunManifest::validatedRunId($runId);
        if ($validatedRunId === null) {
            throw AttachmentError::storage('Artifact run ID is unsafe');
        }
        $runId = $validatedRunId;

        $parent = $this->ensureParent();
        $directory = $parent . '/' . $runId;
        if (!ErrorTrap::run(static fn() => \mkdir($directory, 0o700), $warning)) {
            throw AttachmentError::storage('Failed to create artifact run directory' . ($warning === null ? '' : ': ' . $warning));
        }

        $lockPath = $directory . '/' . self::LOCK_FILE;
        $lock = ErrorTrap::run(static fn() => \fopen($lockPath, 'x+'), $warning);
        if ($lock === false || !ErrorTrap::run(static fn() => \flock($lock, \LOCK_EX), $lockWarning)) {
            if (\is_resource($lock)) {
                ErrorTrap::run(static fn() => \fclose($lock));
            }
            ErrorTrap::run(static fn() => \unlink($lockPath));
            ErrorTrap::run(static fn() => \rmdir($directory));
            throw AttachmentError::storage('Failed to lock artifact run directory' . (($lockWarning ?? $warning) === null ? '' : ': ' . ($lockWarning ?? $warning)));
        }
        ErrorTrap::run(static fn() => \chmod($lockPath, 0o600));

        try {
            RunManifest::write($directory, [
                'version' => RunManifest::VERSION,
                'owner' => RunManifest::OWNER,
                'runId' => $runId,
                'state' => 'active',
                'startedAt' => \time(),
            ]);
        } catch (\Throwable $failure) {
            ErrorTrap::run(static fn() => \flock($lock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($lock));
            ErrorTrap::run(static fn() => \unlink($lockPath));
            ErrorTrap::run(static fn() => \rmdir($directory));
            throw AttachmentError::storage($failure->getMessage());
        }

        return new ArtifactRunHandle($runId, $directory, $lock);
    }

    /**
     * Applies the configured policy to completed runs below the parent.
     *
     * Selection uses run metadata and file sizes only. Before deletion, the
     * method claims each selected run with a rename and verifies the SHA-256
     * digest of each file against the completion manifest.
     *
     * When $reportSkipped is true, the report has a warning for each run
     * directory that the method does not select.
     *
     * When $abandonedRunAgeSeconds is not null, the method also selects
     * abandoned runs. An abandoned run is active, no process holds it, and it
     * started at least that many seconds before $now.
     */
    public function prune(
        bool $dryRun = false,
        ?string $protectedRunId = null,
        ?int $now = null,
        ?int $abandonedRunAgeSeconds = null,
        bool $reportSkipped = true,
    ): ArtifactPruneReport {
        return new RunPruner($this->configuration, $this->parent)
            ->prune($dryRun, $protectedRunId, $now, $abandonedRunAgeSeconds, $reportSkipped);
    }

    /**
     * @param array<non-empty-string, array{bytes: int, sha256: non-empty-string}> $known
     *
     * @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}>
     * @throws \RuntimeException
     */
    public static function contentManifest(string $directory, array $known = []): array
    {
        return RunManifest::contentManifest($directory, $known);
    }

    /**
     * @param array<string, mixed> $metadata
     * @throws \RuntimeException
     */
    public static function writeMetadata(string $directory, array $metadata): void
    {
        RunManifest::write($directory, $metadata);
    }

    /**
     * @throws \JsonException
     * @throws \RuntimeException
     */
    public static function startedAt(string $directory, string $runId): int
    {
        return RunManifest::startedAt($directory, $runId);
    }

    /** @throws AttachmentError */
    private function ensureParent(): string
    {
        try {
            SafeDirectory::create($this->parent);
        } catch (DirectoryWalkError $error) {
            throw OutputDirectoryErrors::from($error);
        }
        $canonical = ErrorTrap::run(fn() => \realpath($this->parent));
        if ($canonical === false || $canonical !== $this->parent) {
            throw AttachmentError::storage('Artifact parent directory is not canonical');
        }

        return $canonical;
    }
}
