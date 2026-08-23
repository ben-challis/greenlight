<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

final readonly class ArtifactRetentionTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function countPolicyPrunesTheOldestCompletedRunFirst(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-count');
        $retention = $this->retention($parent, maxCompletedRuns: 2);
        $this->completedRun($retention, 'run-oldest', 10, 'one');
        $this->completedRun($retention, 'run-middle', 20, 'two');
        $this->completedRun($retention, 'run-newest', 30, 'three');

        $report = $retention->prune(now: 40);

        Expect::that(\array_map(static fn($item): string => $item->runId, $report->items))
            ->because('the count policy MUST select completed runs in deterministic oldest-first order')
            ->toBe(['run-oldest']);
        Expect::that($report->items[0]->reasons)->toBe(['count']);
        Expect::that(\is_dir($parent . '/run-oldest'))->toBeFalse();
        Expect::that(\is_dir($parent . '/run-middle'))->toBeTrue();
        Expect::that(\is_dir($parent . '/run-newest'))->toBeTrue();
    }

    #[Test]
    public function agePolicyDoesNotTreatFutureCompletionAsOld(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-age');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 10);
        $this->completedRun($retention, 'run-past', 80, 'past');
        $this->completedRun($retention, 'run-future', 110, 'future');

        $report = $retention->prune(now: 100);

        Expect::that(\array_map(static fn($item): string => $item->runId, $report->items))->toBe(['run-past']);
        Expect::that(\is_dir($parent . '/run-future'))
            ->because('a clock change MUST NOT make a future completion eligible for age pruning')
            ->toBeTrue();
    }

    #[Test]
    public function dryRunReportsSelectionWithoutDeletingContent(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-dry-run');
        $retention = $this->retention($parent, maxCompletedRuns: 1);
        $this->completedRun($retention, 'run-first', 10, 'first');
        $this->completedRun($retention, 'run-second', 20, 'second');

        $report = $retention->prune(dryRun: true, now: 30);

        Expect::that($report->dryRun)->toBeTrue();
        Expect::that(\array_map(static fn($item): string => $item->runId, $report->items))->toBe(['run-first']);
        Expect::that(\is_file($parent . '/run-first/evidence.txt'))->toBeTrue();
        Expect::that(\is_file($parent . '/.greenlight-prune.lock'))
            ->because('dry-run maintenance MUST NOT change the artifact parent')
            ->toBeFalse();
    }

    #[Test]
    public function activeAndIncompleteRunsAreNeverEligible(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-active');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        $active = $retention->begin('run-active');
        $this->cleanup->defer($active->close(...));

        $report = $retention->prune(now: \PHP_INT_MAX);
        $active->close();
        $secondReport = $retention->prune(now: \PHP_INT_MAX);

        Expect::that($report->items)->toBe([]);
        Expect::that($secondReport->items)
            ->because('an unlocked active marker identifies an incomplete recoverable run')
            ->toBe([]);
        Expect::that(\is_dir($parent . '/run-active'))->toBeTrue();
    }

    #[Test]
    public function unknownMalformedFutureAndChangedRunsRemainUntouched(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-unknown');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        \mkdir($parent . '/user-content');
        \file_put_contents($parent . '/user-content/keep.txt', 'keep');
        $this->metadataDirectory($parent, 'run-malformed', '{');
        $this->metadataDirectory($parent, 'run-future-version', \json_encode([
            'version' => ArtifactRetention::METADATA_VERSION + 1,
            'owner' => ArtifactRetention::OWNER,
            'runId' => 'run-future-version',
            'state' => 'completed',
            'startedAt' => 1,
            'completedAt' => 2,
            'files' => [],
        ], \JSON_THROW_ON_ERROR));
        $this->completedRun($retention, 'run-changed', 2, 'owned');
        \file_put_contents($parent . '/run-changed/user.txt', 'user');

        $report = $retention->prune(now: 100);

        Expect::that($report->items)->toBe([]);
        Expect::that(\file_get_contents($parent . '/user-content/keep.txt'))->toBe('keep');
        Expect::that(\is_dir($parent . '/run-malformed'))->toBeTrue();
        Expect::that(\is_dir($parent . '/run-future-version'))->toBeTrue();
        Expect::that(\file_get_contents($parent . '/run-changed/user.txt'))
            ->because('content that is absent from the completion manifest MUST NOT be deleted')
            ->toBe('user');
    }

    #[Test]
    public function symbolicLinksAndTheirTargetsRemainUntouched(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-symlink');
        $outside = $this->tempDirectory->subdirectory('retention-symlink-target');
        $sentinel = $outside . '/sentinel.txt';
        \file_put_contents($sentinel, 'keep');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        $this->completedRun($retention, 'run-link', 2, 'owned');
        \symlink($outside, $parent . '/run-link/external');
        \symlink($outside, $parent . '/linked-run');

        $report = $retention->prune(now: 100);

        Expect::that($report->items)->toBe([]);
        Expect::that(\file_get_contents($sentinel))
            ->because('retention MUST NOT follow or remove a symbolic link target')
            ->toBe('keep');
        Expect::that(\is_link($parent . '/run-link/external'))->toBeTrue();
        Expect::that(\is_link($parent . '/linked-run'))->toBeTrue();
        Expect::that(static fn() => ArtifactRetention::contentManifest($parent . '/linked-run'))
            ->because('manifest inspection MUST NOT follow a symbolic run root')
            ->toThrow(\RuntimeException::class);
    }

    #[Test]
    public function aConcurrentRunClaimMakesCleanupAdvisory(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-concurrent');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        $this->completedRun($retention, 'run-claimed', 2, 'owned');
        $lock = \fopen($parent . '/run-claimed/' . ArtifactRetention::LOCK_FILE, 'r+');
        if ($lock === false || !\flock($lock, \LOCK_EX | \LOCK_NB)) {
            throw new \RuntimeException('The test did not claim the artifact run lock.');
        }

        try {
            $report = $retention->prune(now: 100);
        } finally {
            \flock($lock, \LOCK_UN);
            \fclose($lock);
        }

        Expect::that($report->items)->toBe([]);
        Expect::that(\is_dir($parent . '/run-claimed'))
            ->because('a concurrent claim MUST make the run ineligible for this prune operation')
            ->toBeTrue();
    }

    #[Test]
    public function sizePolicyUsesSaturatingOldestFirstAccounting(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-size');
        $retention = $this->retention($parent, maxRetainedBytes: 1);
        $this->completedRun($retention, 'run-one', 10, 'one');
        $this->completedRun($retention, 'run-two', 20, 'two');

        $report = $retention->prune(now: 30);

        Expect::that(\array_map(static fn($item): string => $item->runId, $report->items))
            ->because('the byte policy MUST select each required run in oldest-first order')
            ->toBe(['run-one', 'run-two']);
        Expect::that($report->items[0]->reasons)->toBe(['size']);
        Expect::that($report->items[1]->reasons)->toBe(['size']);
    }

    #[Test]
    public function traversalAndOverflowMetadataCannotClaimUserContent(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-malicious-metadata');
        $outside = $this->tempDirectory->path() . '/outside.txt';
        \file_put_contents($outside, 'keep');
        $runId = 'run-malicious';
        $this->metadataDirectory($parent, $runId, \json_encode([
            'version' => ArtifactRetention::METADATA_VERSION,
            'owner' => ArtifactRetention::OWNER,
            'runId' => $runId,
            'state' => 'completed',
            'startedAt' => 1,
            'completedAt' => 2,
            'files' => [
                '../outside.txt' => ['bytes' => \PHP_INT_MAX, 'sha256' => \str_repeat('0', 64)],
            ],
        ], \JSON_THROW_ON_ERROR));
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);

        $report = $retention->prune(now: \PHP_INT_MAX);

        Expect::that($report->items)->toBe([]);
        Expect::that(\file_get_contents($outside))
            ->because('traversal and oversized metadata MUST NOT select content outside the artifact parent')
            ->toBe('keep');
    }

    #[Test]
    public function cleanupFailureIsAdvisory(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-cleanup-failure');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        $this->completedRun($retention, 'run-read-only', 2, 'owned');
        \chmod($parent . '/run-read-only', 0o500);

        try {
            $report = $retention->prune(now: 100);
        } finally {
            if (\is_dir($parent . '/run-read-only')) {
                \chmod($parent . '/run-read-only', 0o700);
            }
            $claims = \glob($parent . '/.greenlight-prune-*', \GLOB_ONLYDIR);
            foreach ($claims === false ? [] : $claims as $claim) {
                \chmod($claim, 0o700);
            }
        }

        Expect::that($report->items)->toBe([]);
        Expect::that($report->warnings)
            ->because('a cleanup failure MUST remain an advisory retention result')
            ->toBe(['Greenlight did not prune artifact run "run-read-only".']);
    }

    #[Test]
    public function combinedLimitsUseAgeThenCountThenSize(): void
    {
        $parent = $this->tempDirectory->subdirectory('retention-precedence');
        $retention = $this->retention(
            $parent,
            maxCompletedRuns: 1,
            maxCompletedRunAgeSeconds: 50,
            maxRetainedBytes: 1,
        );
        $this->completedRun($retention, 'run-aged', 10, 'one');
        $this->completedRun($retention, 'run-counted', 60, 'two');
        $this->completedRun($retention, 'run-sized', 70, 'three');

        $report = $retention->prune(dryRun: true, now: 100);

        Expect::that(\array_map(static fn($item): array => [$item->runId, $item->reasons], $report->items))
            ->because('combined retention MUST use the documented policy precedence')
            ->toBe([
                ['run-aged', ['age']],
                ['run-counted', ['count']],
                ['run-sized', ['size']],
            ]);
    }

    private function retention(
        string $parent,
        ?int $maxCompletedRuns = null,
        ?int $maxCompletedRunAgeSeconds = null,
        ?int $maxRetainedBytes = null,
    ): ArtifactRetention {
        return ArtifactRetention::forConfiguration(new ArtifactConfiguration(
            directory: $parent,
            maxCompletedRuns: $maxCompletedRuns,
            maxCompletedRunAgeSeconds: $maxCompletedRunAgeSeconds,
            maxRetainedBytes: $maxRetainedBytes,
        ), $parent);
    }

    private function completedRun(ArtifactRetention $retention, string $runId, int $completedAt, string $content): void
    {
        $handle = $retention->begin($runId);
        \file_put_contents($handle->directory . '/evidence.txt', $content);
        $handle->complete();
        $metadataPath = $handle->directory . '/' . ArtifactRetention::METADATA_FILE;
        $metadata = \json_decode((string) \file_get_contents($metadataPath), true, flags: \JSON_THROW_ON_ERROR);
        if (!\is_array($metadata)) {
            throw new \RuntimeException('The test did not decode artifact metadata.');
        }
        $map = [];
        foreach ($metadata as $key => $value) {
            $map[(string) $key] = $value;
        }
        $map['completedAt'] = $completedAt;
        ArtifactRetention::writeMetadata($handle->directory, $map);
    }

    private function metadataDirectory(string $parent, string $runId, string $metadata): void
    {
        $directory = $parent . '/' . $runId;
        \mkdir($directory);
        \file_put_contents($directory . '/' . ArtifactRetention::LOCK_FILE, '');
        \file_put_contents($directory . '/' . ArtifactRetention::METADATA_FILE, $metadata);
    }
}
