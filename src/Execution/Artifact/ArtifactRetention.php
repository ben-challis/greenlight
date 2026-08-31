<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Owns run metadata and safely applies retention below one artifact parent.
 *
 * @internal
 */
final readonly class ArtifactRetention
{
    public const int METADATA_VERSION = 1;
    public const string OWNER = 'greenlight';
    public const string METADATA_FILE = '.greenlight-run.json';
    public const string LOCK_FILE = '.greenlight-run.lock';

    private const string PRUNE_LOCK_FILE = '.greenlight-prune.lock';
    private const int MAX_METADATA_BYTES = 16 * 1024 * 1024;

    /** @param non-empty-string $parent */
    private function __construct(
        private ArtifactConfiguration $configuration,
        public string $parent,
    ) {}

    /** @throws AttachmentError */
    public static function forConfiguration(ArtifactConfiguration $configuration, string $workingDirectory): self
    {
        $configured = \rtrim($configuration->directory, '/');
        if ($configured === '' || \str_contains($configured, "\0")) {
            throw AttachmentError::storage('Attachment output directory is invalid');
        }
        if (!\str_starts_with($configured, '/') && \in_array('..', \explode('/', $configured), true)) {
            throw AttachmentError::storage('Keep a relative attachment output directory inside the working directory');
        }

        $workingReal = ErrorTrap::run(static fn() => \realpath($workingDirectory));
        $workingDirectory = $workingReal === false ? $workingDirectory : $workingReal;
        $parent = \str_starts_with($configured, '/')
            ? $configured
            : \rtrim($workingDirectory, '/') . '/' . $configured;

        return new self($configuration, $parent);
    }

    /** @return non-empty-string */
    public function runDirectory(string $runId): string
    {
        return $this->parent . '/' . $runId;
    }

    /** @throws AttachmentError */
    public function begin(string $runId): ArtifactRunHandle
    {
        $validatedRunId = $this->validatedRunId($runId);
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
        \chmod($lockPath, 0o600);

        try {
            self::writeMetadata($directory, [
                'version' => self::METADATA_VERSION,
                'owner' => self::OWNER,
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

    public function prune(bool $dryRun = false, ?string $protectedRunId = null, ?int $now = null): ArtifactPruneReport
    {
        try {
            return $this->applyPolicy($dryRun, $protectedRunId, $now ?? \time());
        } catch (\Throwable) {
            return new ArtifactPruneReport(
                warnings: ['Greenlight did not apply the artifact retention policy.'],
                dryRun: $dryRun,
            );
        }
    }

    private function applyPolicy(bool $dryRun, ?string $protectedRunId, int $now): ArtifactPruneReport
    {
        if (!$this->configuration->hasRetentionPolicy() || !\is_dir($this->parent) || \is_link($this->parent)) {
            return new ArtifactPruneReport(dryRun: $dryRun);
        }

        $canonical = ErrorTrap::run(fn() => \realpath($this->parent));
        if ($canonical === false || $canonical !== $this->parent) {
            return new ArtifactPruneReport(warnings: ['Greenlight did not prune artifacts because the artifact parent is not canonical.'], dryRun: $dryRun);
        }

        if ($dryRun) {
            return $this->pruneLocked($canonical, true, $protectedRunId, $now);
        }

        $pruneLock = ErrorTrap::run(fn() => \fopen($canonical . '/' . self::PRUNE_LOCK_FILE, 'c+'));
        if ($pruneLock === false || !ErrorTrap::run(static fn() => \flock($pruneLock, \LOCK_EX))) {
            if (\is_resource($pruneLock)) {
                ErrorTrap::run(static fn() => \fclose($pruneLock));
            }
            return new ArtifactPruneReport(warnings: ['Greenlight did not lock the artifact parent for pruning.'], dryRun: $dryRun);
        }
        \chmod($canonical . '/' . self::PRUNE_LOCK_FILE, 0o600);

        try {
            if (!$this->canonicalDirectory($canonical, $canonical)) {
                return new ArtifactPruneReport(
                    warnings: ['Greenlight did not prune artifacts because the artifact parent changed.'],
                );
            }

            return $this->pruneLocked($canonical, $dryRun, $protectedRunId, $now);
        } finally {
            ErrorTrap::run(static fn() => \flock($pruneLock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($pruneLock));
        }
    }

    /**
     * @param non-empty-string $parent
     */
    private function pruneLocked(string $parent, bool $dryRun, ?string $protectedRunId, int $now): ArtifactPruneReport
    {
        $records = [];
        $warnings = [];
        $iterator = ErrorTrap::run(static fn() => new \FilesystemIterator($parent, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink() || !$entry->isDir()) {
                continue;
            }
            $record = $this->completedRecord($entry->getPathname(), $entry->getFilename());
            if ($record instanceof ArtifactRunRecord) {
                $records[] = $record;
            }
        }

        \usort($records, static fn(ArtifactRunRecord $a, ArtifactRunRecord $b): int => [$a->completedAt, $a->runId] <=> [$b->completedAt, $b->runId]);
        /** @var array<non-empty-string, non-empty-list<'age'|'count'|'size'>> $selected */
        $selected = [];
        $age = $this->configuration->maxCompletedRunAgeSeconds;
        if ($age !== null) {
            foreach ($records as $record) {
                if ($record->runId !== $protectedRunId && $record->completedAt <= $now && $now - $record->completedAt >= $age) {
                    $selected[$record->runId] = ['age'];
                }
            }
        }

        $remainingCount = \count($records) - \count($selected);
        $countLimit = $this->configuration->maxCompletedRuns;
        if ($countLimit !== null) {
            foreach ($records as $record) {
                if ($remainingCount <= $countLimit) {
                    break;
                }
                if ($record->runId === $protectedRunId || isset($selected[$record->runId])) {
                    continue;
                }
                $selected[$record->runId] = ['count'];
                --$remainingCount;
            }
        }

        $remainingBytes = 0;
        foreach ($records as $record) {
            if (!isset($selected[$record->runId])) {
                $remainingBytes = $this->saturatingAdd($remainingBytes, $record->bytes);
            }
        }
        $byteLimit = $this->configuration->maxRetainedBytes;
        if ($byteLimit !== null) {
            foreach ($records as $record) {
                if ($remainingBytes <= $byteLimit) {
                    break;
                }
                if ($record->runId === $protectedRunId || isset($selected[$record->runId])) {
                    continue;
                }
                $selected[$record->runId] = ['size'];
                $remainingBytes = \max(0, $remainingBytes - $record->bytes);
            }
        }

        $items = [];
        foreach ($records as $record) {
            $reasons = $selected[$record->runId] ?? null;
            if ($reasons === null) {
                continue;
            }
            $directory = $parent . '/' . $record->runId;
            if (!$dryRun && !$this->removeClaimed($parent, $directory, $record)) {
                $warnings[] = \sprintf('Greenlight did not prune artifact run "%s".', $record->runId);
                continue;
            }
            $items[] = new ArtifactPruneItem($record->runId, $directory, $record->bytes, $reasons);
        }

        return new ArtifactPruneReport($items, $warnings, $dryRun);
    }

    private function removeClaimed(string $parent, string $directory, ArtifactRunRecord $record): bool
    {
        if (\dirname($directory) !== $parent || !$this->canonicalDirectory($directory, $directory)) {
            return false;
        }

        $lockPath = $directory . '/' . self::LOCK_FILE;
        if ($this->pathIsLink($lockPath)) {
            return false;
        }
        $lock = ErrorTrap::run(static fn() => \fopen($lockPath, 'r+'));
        if ($lock === false || !ErrorTrap::run(static fn() => \flock($lock, \LOCK_EX | \LOCK_NB))) {
            if (\is_resource($lock)) {
                ErrorTrap::run(static fn() => \fclose($lock));
            }
            return false;
        }

        $claim = $parent . '/.greenlight-prune-' . \bin2hex(\random_bytes(12));
        try {
            if ($this->pathIsLink($lockPath)
                || !$this->canonicalDirectory($parent, $parent)
                || !$this->canonicalDirectory($directory, $directory)
            ) {
                return false;
            }
            if (!ErrorTrap::run(static fn() => \rename($directory, $claim))) {
                return false;
            }
            $claimed = $this->canonicalDirectory($claim, $claim)
                ? $this->recordWithoutLock($claim, $record->runId)
                : null;
            if (!$claimed instanceof ArtifactRunRecord
                || $claimed->files !== $record->files
                || !$this->treeRemovable($claim)
            ) {
                ErrorTrap::run(static fn() => \rename($claim, $directory));
                return false;
            }
        } finally {
            ErrorTrap::run(static fn() => \flock($lock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($lock));
        }

        if ($this->removeTree($claim)) {
            return true;
        }
        ErrorTrap::run(static fn() => \rename($claim, $directory));

        return false;
    }

    /** @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}> */
    public static function contentManifest(string $directory): array
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))
            || !ErrorTrap::run(static fn() => \is_dir($directory))
        ) {
            throw new \RuntimeException('Artifact run root is not a directory owned by Greenlight.');
        }

        $files = [];
        $directories = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        foreach ($entries as $entry) {
            if (!$entry instanceof \SplFileInfo || $entry->isLink()) {
                if ($entry instanceof \SplFileInfo && $entry->isLink()) {
                    throw new \RuntimeException('Artifact run content contains a symbolic link.');
                }
                continue;
            }
            if (!$entry->isFile()) {
                if ($entry->isDir()) {
                    $directories[] = \substr($entry->getPathname(), \strlen($directory) + 1);

                    continue;
                }

                throw new \RuntimeException('Artifact run content contains an unsupported filesystem entry.');
            }
            $relative = self::validatedRelativePath(\substr($entry->getPathname(), \strlen($directory) + 1));
            if ($relative === null) {
                throw new \RuntimeException('Artifact run content contains an unsafe path.');
            }
            if (\in_array($relative, [self::METADATA_FILE, self::LOCK_FILE], true)) {
                continue;
            }
            $size = $entry->getSize();
            $hash = ErrorTrap::run(static fn() => \hash_file('sha256', $entry->getPathname()));
            if (!\is_int($size) || $size < 0 || !\is_string($hash) || $hash === '') {
                throw new \RuntimeException('Greenlight did not read artifact run content.');
            }
            $files[$relative] = ['bytes' => $size, 'sha256' => $hash];
        }
        \ksort($files);
        $expectedDirectories = [];
        foreach (\array_keys($files) as $file) {
            $parent = \dirname($file);
            while ($parent !== '.') {
                $expectedDirectories[$parent] = true;
                $parent = \dirname($parent);
            }
        }
        \sort($directories);
        $expected = \array_keys($expectedDirectories);
        \sort($expected);
        if ($directories !== $expected) {
            throw new \RuntimeException('Artifact run content contains an unowned directory.');
        }

        return $files;
    }

    /** @param array<string, mixed> $metadata */
    public static function writeMetadata(string $directory, array $metadata): void
    {
        $path = $directory . '/' . self::METADATA_FILE;
        $part = $path . '.part-' . \bin2hex(\random_bytes(6));
        $encoded = \json_encode($metadata, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);
        if (ErrorTrap::run(static fn() => \file_put_contents($part, $encoded . "\n", \LOCK_EX)) === false) {
            ErrorTrap::run(static fn() => \unlink($part));
            throw new \RuntimeException('Greenlight did not write artifact run metadata.');
        }
        \chmod($part, 0o600);
        if (!ErrorTrap::run(static fn() => \rename($part, $path))) {
            ErrorTrap::run(static fn() => \unlink($part));
            throw new \RuntimeException('Greenlight did not finalize artifact run metadata.');
        }
    }

    public static function startedAt(string $directory, string $runId): int
    {
        $decoded = self::metadataMap($directory);
        if (($decoded['version'] ?? null) !== self::METADATA_VERSION
            || ($decoded['owner'] ?? null) !== self::OWNER
            || ($decoded['runId'] ?? null) !== $runId
            || ($decoded['state'] ?? null) !== 'active'
            || !\is_int($decoded['startedAt'] ?? null)
            || $decoded['startedAt'] < 0
        ) {
            throw new \RuntimeException('Artifact run metadata is not an active Greenlight record.');
        }

        return $decoded['startedAt'];
    }

    private function completedRecord(string $directory, string $runId): ?ArtifactRunRecord
    {
        if (!$this->safeRunId($runId) || \is_link($directory)) {
            return null;
        }
        $lockPath = $directory . '/' . self::LOCK_FILE;
        if (\is_link($lockPath) || !\is_file($lockPath)) {
            return null;
        }
        $lock = ErrorTrap::run(static fn() => \fopen($lockPath, 'r+'));
        if ($lock === false || !ErrorTrap::run(static fn() => \flock($lock, \LOCK_EX | \LOCK_NB))) {
            if (\is_resource($lock)) {
                ErrorTrap::run(static fn() => \fclose($lock));
            }
            return null;
        }
        try {
            return $this->recordWithoutLock($directory, $runId);
        } catch (\Throwable) {
            return null;
        } finally {
            ErrorTrap::run(static fn() => \flock($lock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($lock));
        }
    }

    private function recordWithoutLock(string $directory, string $runId): ?ArtifactRunRecord
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))
            || !ErrorTrap::run(static fn() => \is_dir($directory))
        ) {
            return null;
        }

        $validatedRunId = $this->validatedRunId($runId);
        if ($validatedRunId === null) {
            return null;
        }
        $runId = $validatedRunId;
        $decoded = self::metadataMap($directory);
        if (($decoded['version'] ?? null) !== self::METADATA_VERSION
            || ($decoded['owner'] ?? null) !== self::OWNER
            || ($decoded['runId'] ?? null) !== $runId
            || ($decoded['state'] ?? null) !== 'completed'
            || !\is_int($decoded['startedAt'] ?? null) || $decoded['startedAt'] < 0
            || !\is_int($decoded['completedAt'] ?? null) || $decoded['completedAt'] < 0
            || !\is_array($decoded['files'] ?? null)
        ) {
            return null;
        }
        $files = $this->validatedFiles($decoded['files']);
        if ($files === null || self::contentManifest($directory) !== $files) {
            return null;
        }
        $bytes = $this->manifestBytes($files);
        foreach ([self::METADATA_FILE, self::LOCK_FILE] as $internal) {
            $size = ErrorTrap::run(static fn() => \filesize($directory . '/' . $internal));
            if (!\is_int($size) || $size < 0) {
                return null;
            }
            $bytes = $this->saturatingAdd($bytes, $size);
        }

        return new ArtifactRunRecord($runId, $decoded['startedAt'], $decoded['completedAt'], $files, $bytes);
    }

    /** @return array<string, mixed> */
    private static function metadataMap(string $directory): array
    {
        $path = $directory . '/' . self::METADATA_FILE;
        if (\is_link($path) || !\is_file($path)) {
            return [];
        }
        $size = ErrorTrap::run(static fn() => \filesize($path));
        if (!\is_int($size) || $size < 1 || $size > self::MAX_METADATA_BYTES) {
            return [];
        }
        $decoded = \json_decode((string) ErrorTrap::run(static fn() => \file_get_contents($path)), true, flags: \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded) || \array_is_list($decoded)) {
            return [];
        }
        $map = [];
        foreach ($decoded as $key => $value) {
            if (\is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    /**
     * @param array<mixed> $raw
     * @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}>|null
     */
    private function validatedFiles(array $raw): ?array
    {
        $files = [];
        foreach ($raw as $path => $metadata) {
            $validatedPath = \is_string($path) ? self::validatedRelativePath($path) : null;
            if ($validatedPath === null || !\is_array($metadata)
                || !\is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 0
                || !\is_string($metadata['sha256'] ?? null)
                || \preg_match('/^[a-f0-9]{64}$/D', $metadata['sha256']) !== 1
            ) {
                return null;
            }
            $files[$validatedPath] = ['bytes' => $metadata['bytes'], 'sha256' => $metadata['sha256']];
        }
        \ksort($files);

        return $files;
    }

    /** @throws AttachmentError */
    private function ensureParent(): string
    {
        $this->createDirectorySafely($this->parent);
        $canonical = ErrorTrap::run(fn() => \realpath($this->parent));
        if ($canonical === false || $canonical !== $this->parent) {
            throw AttachmentError::storage('Artifact parent directory is not canonical');
        }

        return $canonical;
    }

    /** @throws AttachmentError */
    private function createDirectorySafely(string $directory): void
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))) {
            throw AttachmentError::storage('Attachment output directory contains a symbolic link');
        }
        if (ErrorTrap::run(static fn() => \is_dir($directory))) {
            return;
        }

        $missing = [];
        $current = $directory;
        while (true) {
            if (ErrorTrap::run(static fn() => \is_link($current))) {
                throw AttachmentError::storage('Attachment output directory contains a symbolic link');
            }
            if (ErrorTrap::run(static fn() => \is_dir($current))) {
                break;
            }
            if (ErrorTrap::run(static fn() => \file_exists($current))) {
                throw AttachmentError::storage('Attachment output path contains a non-directory entry');
            }
            $missing[] = $current;
            $parent = \dirname($current);
            if ($parent === $current) {
                throw AttachmentError::storage('Failed to find the artifact output parent');
            }
            $current = $parent;
        }

        foreach (\array_reverse($missing) as $path) {
            if (!ErrorTrap::run(static fn() => \mkdir($path, 0o700), $warning)) {
                throw AttachmentError::storage('Failed to create attachment output directory' . ($warning === null ? '' : ': ' . $warning));
            }
        }
    }

    private function safeRunId(string $runId): bool
    {
        return $this->validatedRunId($runId) !== null;
    }

    /**
     * @return non-empty-string|null
     */
    private function validatedRunId(string $runId): ?string
    {
        if ($runId === '' || \in_array($runId, ['.', '..'], true)
            || \preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $runId) !== 1
        ) {
            return null;
        }

        return $runId;
    }

    /** @return non-empty-string|null */
    private static function validatedRelativePath(string $path): ?string
    {
        if ($path === '' || \str_starts_with($path, '/') || \str_contains($path, '\\')
            || !\array_all(\explode('/', $path), static fn(string $part): bool => !\in_array($part, ['', '.', '..'], true))
        ) {
            return null;
        }

        return $path;
    }

    /** @param array<non-empty-string, array{bytes: int, sha256: non-empty-string}> $files */
    private function manifestBytes(array $files): int
    {
        $bytes = 0;
        foreach ($files as $file) {
            $bytes = $this->saturatingAdd($bytes, $file['bytes']);
        }

        return $bytes;
    }

    private function saturatingAdd(int $left, int $right): int
    {
        return $left > \PHP_INT_MAX - $right ? \PHP_INT_MAX : $left + $right;
    }

    private function removeTree(string $directory): bool
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))
            || !ErrorTrap::run(static fn() => \is_dir($directory))
        ) {
            return false;
        }

        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if (!$entry instanceof \SplFileInfo) {
                    continue;
                }
                $path = $entry->getPathname();
                $removed = !$entry->isLink() && $entry->isDir()
                    ? ErrorTrap::run(static fn() => \rmdir($path))
                    : ErrorTrap::run(static fn() => \unlink($path));
                if (!$removed) {
                    return false;
                }
            }

            return ErrorTrap::run(static fn() => \rmdir($directory));
        } catch (\Throwable) {
            return false;
        }
    }

    private function treeRemovable(string $directory): bool
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))
            || !ErrorTrap::run(static fn() => \is_dir($directory))
            || !ErrorTrap::run(static fn() => \is_writable($directory))
        ) {
            return false;
        }
        try {
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::SELF_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry instanceof \SplFileInfo && $entry->isDir() && !$entry->isLink()
                    && !ErrorTrap::run(static fn() => \is_writable($entry->getPathname()))
                ) {
                    return false;
                }
            }

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /** @phpstan-impure */
    private function canonicalDirectory(string $directory, string $expected): bool
    {
        return !ErrorTrap::run(static fn() => \is_link($directory))
            && ErrorTrap::run(static fn() => \is_dir($directory))
            && ErrorTrap::run(static fn() => \realpath($directory)) === $expected;
    }

    /** @phpstan-impure */
    private function pathIsLink(string $path): bool
    {
        return ErrorTrap::run(static fn() => \is_link($path));
    }
}
