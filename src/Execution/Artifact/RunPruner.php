<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Applies retention below one canonical artifact parent.
 *
 * prune() takes the parent prune lock, except for a dry run. It recovers
 * claims that an interrupted prune left, classifies each run directory from
 * metadata and `stat` data, and asks RetentionPolicy for a selection. For each
 * selected run, it claims the directory with a rename, verifies the claim, and
 * deletes it. A failed verification renames the claim back. The report has a
 * warning for each directory that the method did not select or remove.
 *
 * @internal
 */
final readonly class RunPruner
{
    private const string PRUNE_LOCK_FILE = '.greenlight-prune.lock';
    private const string CLAIM_PREFIX = '.greenlight-prune-';

    private RetentionPolicy $policy;

    /** @param non-empty-string $parent */
    public function __construct(
        private ArtifactConfiguration $configuration,
        private string $parent,
    ) {
        $this->policy = new RetentionPolicy($configuration);
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
            || !ErrorTrap::run(fn() => \is_dir($this->parent))
            || ErrorTrap::run(fn() => \is_link($this->parent))
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
        $pruneLockPath = $canonical . '/' . self::PRUNE_LOCK_FILE;
        ErrorTrap::run(static fn() => \chmod($pruneLockPath, 0o600));

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
            foreach ($this->policy->select($remaining, $unselectable, $now) as [$record, $reasons]) {
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
        $runId = RunManifest::validatedRunId($name);
        if ($runId === null) {
            return 'its name is not a Greenlight run ID';
        }
        $lockPath = $directory . '/' . RunManifest::LOCK_FILE;
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
        $decoded = RunManifest::read($directory);
        $startedAt = RunManifest::ownedStartedAt($decoded, $runId);
        if ($startedAt === null) {
            return 'it has no valid Greenlight run metadata';
        }

        if (($decoded['state'] ?? null) === 'active') {
            if ($abandonedRunAgeSeconds === null || $startedAt > $now || $now - $startedAt < $abandonedRunAgeSeconds) {
                return 'the run did not complete and no process holds it';
            }
            $sizes = RunManifest::contentSizes($directory, true);

            return new ArtifactRunRecord(
                $runId,
                $startedAt,
                $startedAt,
                [],
                RunManifest::withInternalBytes($directory, \array_sum($sizes)),
                active: true,
            );
        }

        $files = RunManifest::completedFiles($decoded);
        if ($files === null || !\is_int($decoded['completedAt'] ?? null)) {
            return 'it has no valid Greenlight run metadata';
        }
        $expected = \array_map(static fn(array $file): int => $file['bytes'], $files);
        if (RunManifest::contentSizes($directory) !== $expected) {
            return 'its content changed after completion';
        }

        return new ArtifactRunRecord(
            $runId,
            $startedAt,
            $decoded['completedAt'],
            $files,
            RunManifest::withInternalBytes($directory, RunManifest::manifestBytes($files)),
        );
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

        $lockPath = $directory . '/' . RunManifest::LOCK_FILE;
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
            $decoded = RunManifest::read($claim);
            if (($decoded['state'] ?? null) !== 'active'
                || ($decoded['runId'] ?? null) !== $record->runId
                || ($decoded['startedAt'] ?? null) !== $record->startedAt
            ) {
                return $failure;
            }
            RunManifest::contentSizes($claim, true);
        } else {
            $claimed = RunManifest::verifiedRecord($claim, $record->runId);
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
                    $recorded = RunManifest::read($claim)['runId'] ?? null;
                    $runId = \is_string($recorded) ? RunManifest::validatedRunId($recorded) : null;
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
            && \array_diff($names, ['.', '..', RunManifest::LOCK_FILE]) === []
            && !$this->pathIsLink($claim . '/' . RunManifest::LOCK_FILE);
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
        $decoded = RunManifest::read($claim);
        if (($decoded['version'] ?? null) !== RunManifest::VERSION
            || ($decoded['owner'] ?? null) !== RunManifest::OWNER
            || ($decoded['runId'] ?? null) !== $runId
            || ($decoded['state'] ?? null) !== 'completed'
            || !\is_array($decoded['files'] ?? null)
        ) {
            return false;
        }
        $files = RunManifest::validatedFiles($decoded['files']);
        if ($files === null) {
            return false;
        }

        return \array_all(
            RunManifest::contentManifest($claim),
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
            $metadata = $directory . '/' . RunManifest::METADATA_FILE;
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
