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
    private const string CLAIM_PREFIX = '.greenlight-prune-';
    private const int MAX_METADATA_BYTES = 16 * 1024 * 1024;

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
        $this->walkParent(false);
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
        try {
            return $this->applyPolicy($dryRun, $protectedRunId, $now ?? \time(), $abandonedRunAgeSeconds, $reportSkipped);
        } catch (\Throwable) {
            return new ArtifactPruneReport(
                warnings: ['Greenlight did not apply the artifact retention policy.'],
                dryRun: $dryRun,
            );
        }
    }

    private function applyPolicy(
        bool $dryRun,
        ?string $protectedRunId,
        int $now,
        ?int $abandonedRunAgeSeconds,
        bool $reportSkipped,
    ): ArtifactPruneReport {
        if ((!$this->configuration->hasRetentionPolicy() && $abandonedRunAgeSeconds === null)
            || !\is_dir($this->parent)
            || \is_link($this->parent)
        ) {
            return new ArtifactPruneReport(dryRun: $dryRun);
        }

        $canonical = ErrorTrap::run(fn() => \realpath($this->parent));
        if ($canonical === false || $canonical !== $this->parent) {
            return new ArtifactPruneReport(warnings: ['Greenlight did not prune artifacts because the artifact parent is not canonical.'], dryRun: $dryRun);
        }

        if ($dryRun) {
            return $this->pruneLocked($canonical, true, $protectedRunId, $now, $abandonedRunAgeSeconds, $reportSkipped);
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

            return $this->pruneLocked($canonical, $dryRun, $protectedRunId, $now, $abandonedRunAgeSeconds, $reportSkipped);
        } finally {
            ErrorTrap::run(static fn() => \flock($pruneLock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($pruneLock));
        }
    }

    /**
     * @param non-empty-string $parent
     */
    private function pruneLocked(
        string $parent,
        bool $dryRun,
        ?string $protectedRunId,
        int $now,
        ?int $abandonedRunAgeSeconds,
        bool $reportSkipped,
    ): ArtifactPruneReport {
        $warnings = $dryRun ? $this->reportClaims($parent) : $this->recoverClaims($parent);
        $records = [];
        $abandoned = [];
        foreach ($this->entryNames($parent) as $name) {
            if (\str_starts_with($name, '.')) {
                continue;
            }
            $inspection = $this->inspect($parent . '/' . $name, $name, $now, $abandonedRunAgeSeconds);
            if ($inspection instanceof ArtifactRunRecord) {
                if ($inspection->active) {
                    if ($name !== $protectedRunId) {
                        $abandoned[] = $inspection;
                    }
                } else {
                    $records[] = $inspection;
                }
            } elseif ($reportSkipped && $inspection !== null && $name !== $protectedRunId) {
                $warnings[] = \sprintf('Greenlight skipped artifact directory "%s" because %s.', $name, $inspection);
            }
        }

        $items = [];
        $excluded = [];
        $unselectable = $protectedRunId === null ? [] : [$protectedRunId => true];
        do {
            $rejected = false;
            $remaining = \array_values(\array_filter(
                [...$records, ...$abandoned],
                static fn(ArtifactRunRecord $record): bool => !isset($excluded[$record->runId]),
            ));
            foreach ($this->select($remaining, $unselectable, $now) as [$record, $reasons]) {
                $directory = $parent . '/' . $record->runId;
                $failure = $dryRun ? null : $this->removeClaimed($parent, $directory, $record);
                $excluded[$record->runId] = true;
                if ($failure !== null) {
                    // A run that fails verification is not a candidate, as a
                    // changed run is not. The selection runs again without it.
                    $warnings[] = $failure;
                    $rejected = true;
                    continue;
                }
                $items[] = new ArtifactPruneItem($record->runId, $directory, $record->bytes, $reasons);
            }
        } while ($rejected);

        return new ArtifactPruneReport($items, $warnings, $dryRun);
    }

    /**
     * Classifies one entry below the artifact parent.
     *
     * @return ArtifactRunRecord|non-empty-string|null A candidate record, or
     *   the reason that the entry is not a candidate. Null identifies an entry
     *   that needs no report, such as a file or a run that another process holds.
     */
    private function inspect(string $directory, string $name, int $now, ?int $abandonedRunAgeSeconds): ArtifactRunRecord|string|null
    {
        if (ErrorTrap::run(static fn() => \is_link($directory))) {
            return 'it is a symbolic link';
        }
        if (!ErrorTrap::run(static fn() => \is_dir($directory))) {
            return null;
        }
        $runId = $this->validatedRunId($name);
        if ($runId === null) {
            return 'its name is not a Greenlight run ID';
        }
        $lockPath = $directory . '/' . self::LOCK_FILE;
        if ($this->pathIsLink($lockPath) || !ErrorTrap::run(static fn() => \is_file($lockPath))) {
            return 'it has no Greenlight run lock';
        }
        $lock = ErrorTrap::run(static fn() => \fopen($lockPath, 'r+'));
        if ($lock === false) {
            return 'Greenlight did not open its run lock';
        }
        if (!ErrorTrap::run(static fn() => \flock($lock, \LOCK_EX | \LOCK_NB))) {
            ErrorTrap::run(static fn() => \fclose($lock));

            return null;
        }

        try {
            return $this->candidate($directory, $runId, $now, $abandonedRunAgeSeconds);
        } catch (\Throwable $failure) {
            return 'its content is not valid: ' . \rtrim($failure->getMessage(), '.');
        } finally {
            ErrorTrap::run(static fn() => \flock($lock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($lock));
        }
    }

    /**
     * Builds a candidate record from metadata and file sizes. This method
     * does not read file content.
     *
     * @param non-empty-string $runId
     *
     * @return ArtifactRunRecord|non-empty-string
     * @throws \Throwable
     */
    private function candidate(string $directory, string $runId, int $now, ?int $abandonedRunAgeSeconds): ArtifactRunRecord|string
    {
        $decoded = self::metadataMap($directory);
        if (($decoded['version'] ?? null) !== self::METADATA_VERSION
            || ($decoded['owner'] ?? null) !== self::OWNER
            || ($decoded['runId'] ?? null) !== $runId
            || !\is_int($decoded['startedAt'] ?? null) || $decoded['startedAt'] < 0
        ) {
            return 'it has no valid Greenlight run metadata';
        }
        $startedAt = $decoded['startedAt'];

        if (($decoded['state'] ?? null) === 'active') {
            if ($abandonedRunAgeSeconds === null || $startedAt > $now || $now - $startedAt < $abandonedRunAgeSeconds) {
                return 'the run did not complete and no process holds it';
            }
            $sizes = self::contentSizes($directory, true);

            return new ArtifactRunRecord(
                $runId,
                $startedAt,
                $startedAt,
                [],
                $this->withInternalBytes($directory, \array_sum($sizes)),
                active: true,
            );
        }

        if (($decoded['state'] ?? null) !== 'completed'
            || !\is_int($decoded['completedAt'] ?? null) || $decoded['completedAt'] < 0
            || !\is_array($decoded['files'] ?? null)
        ) {
            return 'it has no valid Greenlight run metadata';
        }
        $files = $this->validatedFiles($decoded['files']);
        if ($files === null) {
            return 'it has no valid Greenlight run metadata';
        }
        $expected = \array_map(static fn(array $file): int => $file['bytes'], $files);
        if (self::contentSizes($directory) !== $expected) {
            return 'its content changed after completion';
        }

        return new ArtifactRunRecord(
            $runId,
            $startedAt,
            $decoded['completedAt'],
            $files,
            $this->withInternalBytes($directory, $this->manifestBytes($files)),
        );
    }

    /** @throws \RuntimeException */
    private function withInternalBytes(string $directory, int $bytes): int
    {
        foreach ([self::METADATA_FILE, self::LOCK_FILE] as $internal) {
            $size = ErrorTrap::run(static fn() => \filesize($directory . '/' . $internal));
            if (!\is_int($size) || $size < 0) {
                throw new \RuntimeException('Greenlight did not read artifact run metadata.');
            }
            $bytes = $this->saturatingAdd($bytes, $size);
        }

        return $bytes;
    }

    /**
     * Applies the age, count, and size limits in that order. Each limit
     * selects the oldest remaining completed run first. Each active record,
     * which a caller supplies only for an explicit request to prune abandoned
     * runs, is selected after the completed runs, in start order. A run in
     * $unselectable is never selected, but it counts toward the limits.
     *
     * @param list<ArtifactRunRecord> $records
     * @param array<string, true> $unselectable
     *
     * @return list<array{ArtifactRunRecord, non-empty-list<'abandoned'|'age'|'count'|'size'>}>
     */
    private function select(array $records, array $unselectable, int $now): array
    {
        $abandoned = \array_values(\array_filter(
            $records,
            static fn(ArtifactRunRecord $record): bool => $record->active && !isset($unselectable[$record->runId]),
        ));
        $records = \array_values(\array_filter($records, static fn(ArtifactRunRecord $record): bool => !$record->active));
        \usort($abandoned, static fn(ArtifactRunRecord $a, ArtifactRunRecord $b): int => [$a->startedAt, $a->runId] <=> [$b->startedAt, $b->runId]);
        \usort($records, static fn(ArtifactRunRecord $a, ArtifactRunRecord $b): int => [$a->completedAt, $a->runId] <=> [$b->completedAt, $b->runId]);
        /** @var array<non-empty-string, non-empty-list<'age'|'count'|'size'>> $selected */
        $selected = [];
        $age = $this->configuration->maxCompletedRunAgeSeconds;
        if ($age !== null) {
            foreach ($records as $record) {
                if (!isset($unselectable[$record->runId]) && $record->completedAt <= $now && $now - $record->completedAt >= $age) {
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
                if (isset($unselectable[$record->runId]) || isset($selected[$record->runId])) {
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
                if (isset($unselectable[$record->runId]) || isset($selected[$record->runId])) {
                    continue;
                }
                $selected[$record->runId] = ['size'];
                $remainingBytes = \max(0, $remainingBytes - $record->bytes);
            }
        }

        $selection = [];
        foreach ($records as $record) {
            if (isset($selected[$record->runId])) {
                $selection[] = [$record, $selected[$record->runId]];
            }
        }
        foreach ($abandoned as $record) {
            $selection[] = [$record, ['abandoned']];
        }

        return $selection;
    }

    /**
     * @return non-empty-string|null The warning for a run that the method did
     *   not remove, or null after removal.
     */
    private function removeClaimed(string $parent, string $directory, ArtifactRunRecord $record): ?string
    {
        $failure = \sprintf('Greenlight did not prune artifact run "%s".', $record->runId);
        if (\dirname($directory) !== $parent || !$this->canonicalDirectory($directory, $directory)) {
            return $failure;
        }

        $lockPath = $directory . '/' . self::LOCK_FILE;
        if ($this->pathIsLink($lockPath)) {
            return $failure;
        }
        $lock = ErrorTrap::run(static fn() => \fopen($lockPath, 'r+'));
        if ($lock === false || !ErrorTrap::run(static fn() => \flock($lock, \LOCK_EX | \LOCK_NB))) {
            if (\is_resource($lock)) {
                ErrorTrap::run(static fn() => \fclose($lock));
            }
            return $failure;
        }

        $claim = $parent . '/' . self::CLAIM_PREFIX . \bin2hex(\random_bytes(12));
        try {
            if ($this->pathIsLink($lockPath)
                || !$this->canonicalDirectory($parent, $parent)
                || !$this->canonicalDirectory($directory, $directory)
            ) {
                return $failure;
            }
            if (!ErrorTrap::run(static fn() => \rename($directory, $claim))) {
                return $failure;
            }
            try {
                $verification = $this->claimVerification($claim, $record);
            } catch (\Throwable) {
                $verification = $failure;
            }
            if ($verification !== null) {
                ErrorTrap::run(static fn() => \rename($claim, $directory));
                return $verification;
            }
        } finally {
            ErrorTrap::run(static fn() => \flock($lock, \LOCK_UN));
            ErrorTrap::run(static fn() => \fclose($lock));
        }

        // A failed deletion keeps the claim, so that the next prune can
        // complete it. A partial tree cannot return to its run ID.
        return $this->removeTree($claim) ? null : $failure;
    }

    /**
     * Verifies a claimed run against its candidate record. For a completed
     * run, this check reads each file and compares its SHA-256 digest.
     *
     * @return non-empty-string|null
     * @throws \Throwable
     */
    private function claimVerification(string $claim, ArtifactRunRecord $record): ?string
    {
        $failure = \sprintf('Greenlight did not prune artifact run "%s".', $record->runId);
        if (!$this->canonicalDirectory($claim, $claim)) {
            return $failure;
        }
        if ($record->active) {
            $decoded = self::metadataMap($claim);
            if (($decoded['state'] ?? null) !== 'active'
                || ($decoded['runId'] ?? null) !== $record->runId
                || ($decoded['startedAt'] ?? null) !== $record->startedAt
            ) {
                return $failure;
            }
            self::contentSizes($claim, true);
        } else {
            $claimed = $this->verifiedRecord($claim, $record->runId);
            if (!$claimed instanceof ArtifactRunRecord || $claimed->files !== $record->files) {
                return \sprintf(
                    'Greenlight did not prune artifact run "%s" because its content does not match its completion manifest.',
                    $record->runId,
                );
            }
        }

        return $this->treeRemovable($claim) ? null : $failure;
    }

    /**
     * Completes or reverses claims that an interrupted prune left. The caller
     * holds the prune lock, so no other prune owns these claims.
     *
     * @param non-empty-string $parent
     *
     * @return list<non-empty-string>
     */
    private function recoverClaims(string $parent): array
    {
        $warnings = [];
        foreach ($this->claimNames($parent) as $name) {
            $claim = $parent . '/' . $name;
            $runId = null;
            if (!$this->pathIsLink($claim) && ErrorTrap::run(static fn() => \is_dir($claim))) {
                try {
                    $recorded = self::metadataMap($claim)['runId'] ?? null;
                    $runId = \is_string($recorded) ? $this->validatedRunId($recorded) : null;
                } catch (\Throwable) {
                    // A claim without readable metadata stays for manual review.
                }
            }
            if ($runId === null) {
                // The last step of a deletion removes the metadata file. An
                // empty claim is therefore a deletion that did not finish.
                $warnings[] = $this->emptyClaim($claim) && $this->removeTree($claim)
                    ? \sprintf('Greenlight removed the empty interrupted prune claim "%s".', $name)
                    : \sprintf('Greenlight did not recover the interrupted prune claim "%s".', $name);
                continue;
            }

            try {
                $owned = $this->claimWithinManifest($claim, $runId);
            } catch (\Throwable) {
                $owned = false;
            }
            if ($owned) {
                $warnings[] = $this->treeRemovable($claim) && $this->removeTree($claim)
                    ? \sprintf('Greenlight completed an interrupted prune of artifact run "%s".', $runId)
                    : \sprintf('Greenlight did not recover the interrupted prune claim "%s".', $name);
                continue;
            }

            $target = $parent . '/' . $runId;
            if (!ErrorTrap::run(static fn() => \file_exists($target))
                && !$this->pathIsLink($target)
                && ErrorTrap::run(static fn() => \rename($claim, $target))
            ) {
                $warnings[] = \sprintf('Greenlight restored artifact run "%s" after an interrupted prune.', $runId);
                continue;
            }
            $warnings[] = \sprintf('Greenlight did not recover the interrupted prune claim "%s".', $name);
        }

        return $warnings;
    }

    /**
     * Tells whether a claim directory contains nothing, or only the run lock
     * file.
     */
    private function emptyClaim(string $claim): bool
    {
        if ($this->pathIsLink($claim) || !ErrorTrap::run(static fn() => \is_dir($claim))) {
            return false;
        }
        $names = ErrorTrap::run(static fn() => \scandir($claim));

        return \is_array($names)
            && \array_diff($names, ['.', '..', self::LOCK_FILE]) === []
            && !$this->pathIsLink($claim . '/' . self::LOCK_FILE);
    }

    /**
     * Tells whether each remaining content file of a claim matches its entry
     * in the completion manifest. A claim that a prune deleted in part
     * matches, because deletion only removes files. The metadata file stays
     * until the last step of a deletion.
     *
     * @throws \Throwable
     */
    private function claimWithinManifest(string $claim, string $runId): bool
    {
        $decoded = self::metadataMap($claim);
        if (($decoded['version'] ?? null) !== self::METADATA_VERSION
            || ($decoded['owner'] ?? null) !== self::OWNER
            || ($decoded['runId'] ?? null) !== $runId
            || ($decoded['state'] ?? null) !== 'completed'
            || !\is_array($decoded['files'] ?? null)
        ) {
            return false;
        }
        $files = $this->validatedFiles($decoded['files']);
        if ($files === null) {
            return false;
        }

        return \array_all(
            self::contentManifest($claim),
            static fn(array $file, string $path): bool => ($files[$path] ?? null) === $file,
        );
    }

    /**
     * @param non-empty-string $parent
     *
     * @return list<non-empty-string>
     */
    private function reportClaims(string $parent): array
    {
        return \array_map(
            static fn(string $name): string => \sprintf(
                'Greenlight found the interrupted prune claim "%s". A prune without the dry-run option recovers it.',
                $name,
            ),
            $this->claimNames($parent),
        );
    }

    /** @return list<string> */
    private function claimNames(string $parent): array
    {
        return \array_values(\array_filter(
            $this->entryNames($parent),
            static fn(string $name): bool => \preg_match('/^' . \preg_quote(self::CLAIM_PREFIX, '/') . '[a-f0-9]{24}$/D', $name) === 1,
        ));
    }

    /**
     * @return list<string>
     * @throws \RuntimeException
     */
    private function entryNames(string $parent): array
    {
        $names = ErrorTrap::run(static fn() => \scandir($parent));
        if ($names === false) {
            throw new \RuntimeException('Greenlight did not read the artifact parent.');
        }

        return \array_values(\array_filter($names, static fn(string $name): bool => $name !== '.' && $name !== '..'));
    }

    /**
     * Returns the SHA-256 manifest of the content below a run directory.
     *
     * The method reuses each digest in $known when the file size matches.
     * It reads all other files.
     *
     * @param array<non-empty-string, array{bytes: int, sha256: non-empty-string}> $known
     *
     * @return array<non-empty-string, array{bytes: int, sha256: non-empty-string}>
     * @throws \RuntimeException
     */
    public static function contentManifest(string $directory, array $known = []): array
    {
        $files = [];
        foreach (self::contentSizes($directory) as $relative => $size) {
            $recorded = $known[$relative] ?? null;
            if ($recorded !== null && $recorded['bytes'] === $size) {
                $files[$relative] = $recorded;

                continue;
            }
            $path = $directory . '/' . $relative;
            $hash = ErrorTrap::run(static fn() => \hash_file('sha256', $path));
            if (!\is_string($hash) || $hash === '') {
                throw new \RuntimeException('Greenlight did not read artifact run content.');
            }
            $files[$relative] = ['bytes' => $size, 'sha256' => $hash];
        }

        return $files;
    }

    /**
     * Returns the size of each content file below a run directory. The
     * method uses `stat` data only and does not read file content.
     *
     * A directory without a content file is unowned, unless
     * $allowEmptyDirectories is true. An incomplete run can contain such a
     * directory when a publication failed after it created the directory.
     *
     * @return array<non-empty-string, int>
     * @throws \RuntimeException
     */
    public static function contentSizes(string $directory, bool $allowEmptyDirectories = false): array
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
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isLink()) {
                throw new \RuntimeException('Artifact run content contains a symbolic link.');
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
            if (!\is_int($size) || $size < 0) {
                throw new \RuntimeException('Greenlight did not read artifact run content.');
            }
            $files[$relative] = $size;
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
        if (!$allowEmptyDirectories && $directories !== $expected) {
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

    /**
     * Reads a completed run record and verifies the SHA-256 digest of each
     * content file against the completion manifest.
     *
     * @throws \Throwable
     */
    private function verifiedRecord(string $directory, string $runId): ?ArtifactRunRecord
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
        return new ArtifactRunRecord(
            $runId,
            $decoded['startedAt'],
            $decoded['completedAt'],
            $files,
            $this->withInternalBytes($directory, $this->manifestBytes($files)),
        );
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
        $this->walkParent(true);
        $canonical = ErrorTrap::run(fn() => \realpath($this->parent));
        if ($canonical === false || $canonical !== $this->parent) {
            throw AttachmentError::storage('Artifact parent directory is not canonical');
        }

        return $canonical;
    }

    /**
     * Checks each missing or existing path from the parent up to its first
     * existing directory. When $create is true, the method creates each
     * missing directory.
     *
     * @throws AttachmentError
     */
    private function walkParent(bool $create): void
    {
        $missing = [];
        $current = $this->parent;
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
                if (!$create) {
                    // Restricted filesystem access can hide every ancestor.
                    // Creation reports the failure if access stays restricted.
                    return;
                }
                throw AttachmentError::storage('Failed to find the artifact output parent');
            }
            $current = $parent;
        }

        if (!$create) {
            return;
        }
        foreach (\array_reverse($missing) as $path) {
            if (!ErrorTrap::run(static fn() => \mkdir($path, 0o700), $warning)) {
                throw AttachmentError::storage('Failed to create attachment output directory' . ($warning === null ? '' : ': ' . $warning));
            }
        }
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
            $metadata = $directory . '/' . self::METADATA_FILE;
            foreach ($entries as $entry) {
                if (!$entry instanceof \SplFileInfo || $entry->getPathname() === $metadata) {
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

            return (!ErrorTrap::run(static fn() => \file_exists($metadata)) || ErrorTrap::run(static fn() => \unlink($metadata)))
                && ErrorTrap::run(static fn() => \rmdir($directory));
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
