<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class ArtifactPruneClaimRecoveryTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aStaleClaimThatMatchesItsManifestIsRemoved(): void
    {
        $parent = $this->tempDirectory->subdirectory('stale-claim-removal');
        $retention = $this->retention($parent, maxCompletedRuns: 10);
        $this->completedRun($retention, 'run-claimed', 2, 'owned');
        $claim = $parent . '/.greenlight-prune-' . \str_repeat('a', 24);
        \rename($parent . '/run-claimed', $claim);

        $report = $retention->prune(now: 100);

        expect(\file_exists($claim))
            ->because('a prune MUST complete a claim that an interrupted prune left')
            ->toBeFalse();
        expect(\file_exists($parent . '/run-claimed'))->toBeFalse();
        expect($report->warnings)->toBe(['Greenlight completed an interrupted prune of artifact run "run-claimed".']);
    }

    #[Test]
    public function aPartlyDeletedClaimIsRemoved(): void
    {
        $parent = $this->tempDirectory->subdirectory('stale-claim-partial');
        $retention = $this->retention($parent, maxCompletedRuns: 10);
        $handle = $retention->begin('run-partial');
        \file_put_contents($handle->directory . '/first.txt', 'first');
        \file_put_contents($handle->directory . '/second.txt', 'second');
        $handle->complete();
        $claim = $parent . '/.greenlight-prune-' . \str_repeat('d', 24);
        \rename($parent . '/run-partial', $claim);
        \unlink($claim . '/first.txt');

        $report = $retention->prune(now: 100);

        expect(\file_exists($claim))
            ->because('a prune MUST complete a claim that an earlier prune deleted in part')
            ->toBeFalse();
        expect(\file_exists($parent . '/run-partial'))->toBeFalse();
        expect($report->warnings)->toBe(['Greenlight completed an interrupted prune of artifact run "run-partial".']);
    }

    #[Test]
    public function aClaimThatAFailedRecoveryEmptiedIsRemovedByTheNextPrune(): void
    {
        $parent = $this->tempDirectory->subdirectory('stale-claim-failed-deletion');
        $retention = $this->retention($parent, maxCompletedRuns: 10);
        $retention->prune(now: 100);
        $this->completedRun($retention, 'run-claimed', 2, 'owned');
        $claim = $parent . '/.greenlight-prune-' . \str_repeat('e', 24);
        \rename($parent . '/run-claimed', $claim);
        \chmod($parent, 0o500);

        try {
            $failed = $retention->prune(now: 100);
        } finally {
            \chmod($parent, 0o700);
        }
        $claimAfterFailure = \is_dir($claim);
        $completed = $retention->prune(now: 100);

        expect($claimAfterFailure)
            ->because('a deletion that fails MUST keep the claim, not restore a partial run')
            ->toBeTrue();
        expect(\file_exists($parent . '/run-claimed'))->toBeFalse();
        expect($failed->warnings)->toBe([\sprintf('Greenlight did not recover the interrupted prune claim "%s".', \basename($claim))]);
        expect(\file_exists($claim))
            ->because('the next prune MUST complete a claim that a failed deletion left')
            ->toBeFalse();
        expect($completed->warnings)->toBe([\sprintf('Greenlight removed the empty interrupted prune claim "%s".', \basename($claim))]);
    }

    #[Test]
    public function aClaimWithAnEmptiedDirectoryIsRemoved(): void
    {
        $parent = $this->tempDirectory->subdirectory('stale-claim-emptied-directory');
        $retention = $this->retention($parent, maxCompletedRuns: 10);
        $handle = $retention->begin('run-nested');
        \mkdir($handle->directory . '/nested');
        \file_put_contents($handle->directory . '/nested/first.txt', 'first');
        \file_put_contents($handle->directory . '/second.txt', 'second');
        $handle->complete();
        $claim = $parent . '/.greenlight-prune-' . \str_repeat('f', 24);
        \rename($parent . '/run-nested', $claim);
        \unlink($claim . '/nested/first.txt');

        $report = $retention->prune(now: 100);

        expect(\file_exists($parent . '/run-nested'))
            ->because('a claim that a deletion stopped in before an empty directory MUST NOT return to its run ID')
            ->toBeFalse();
        expect(\file_exists($claim))->toBeFalse();
        expect($report->warnings)->toBe(['Greenlight completed an interrupted prune of artifact run "run-nested".']);
    }

    #[Test]
    public function aFailedDeletionKeepsTheClaimForTheNextPrune(): void
    {
        $parent = $this->tempDirectory->subdirectory('failed-claim-deletion');
        $retention = $this->retention($parent, maxCompletedRuns: 1);
        $this->completedRun($retention, 'run-old', 1, 'old', extraFiles: 2);
        $this->completedRun($retention, 'run-new', 2, 'new');
        $removals = 0;
        $failing = $retention->withPathRemoverForTesting(static function (string $path) use (&$removals): bool {
            ++$removals;

            return $removals === 1 && \unlink($path);
        });

        $failed = $failing->prune(now: 100);
        $claims = $this->claims($parent);
        $metadataKept = \count($claims) === 1 && \is_file($claims[0] . '/' . ArtifactRetention::METADATA_FILE);
        $completed = $retention->prune(now: 100);

        expect($claims)
            ->because('a deletion that fails part way MUST keep the claim')
            ->toHaveCount(1);
        expect(\file_exists($parent . '/run-old'))
            ->because('a partly deleted run MUST NOT return to its run ID')
            ->toBeFalse();
        expect($metadataKept)
            ->because('the metadata file MUST stay until the last step of a deletion')
            ->toBeTrue();
        expect($failed->warnings)->toBe(['Greenlight did not prune artifact run "run-old".']);
        expect($this->claims($parent))->toBe([]);
        expect($completed->warnings)->toBe(['Greenlight completed an interrupted prune of artifact run "run-old".']);
        expect(\is_dir($parent . '/run-new'))->toBeTrue();
    }

    #[Test]
    public function deletionRemovesTheMetadataFileAfterEveryOtherEntry(): void
    {
        $parent = $this->tempDirectory->subdirectory('metadata-removed-last');
        $retention = $this->retention($parent, maxCompletedRuns: 1);
        $this->completedRun($retention, 'run-old', 1, 'old', extraFiles: 32);
        $this->completedRun($retention, 'run-new', 2, 'new');
        $remainingAtMetadata = null;
        $recording = $retention->withPathRemoverForTesting(
            static function (string $path) use (&$remainingAtMetadata): bool {
                if (\basename($path) === ArtifactRetention::METADATA_FILE) {
                    $names = \scandir(\dirname($path));
                    $remainingAtMetadata = \is_array($names) ? \array_values(\array_diff($names, ['.', '..'])) : null;
                }

                return \is_dir($path) && !\is_link($path) ? \rmdir($path) : \unlink($path);
            },
        );

        $report = $recording->prune(now: 100);

        expect($remainingAtMetadata)
            ->because('a deletion MUST remove every other entry before the metadata file')
            ->toBe([ArtifactRetention::METADATA_FILE]);
        expect($report->warnings)->toBe([]);
        expect(\file_exists($parent . '/run-old'))->toBeFalse();
    }

    #[Test]
    public function aStaleClaimWithChangedContentIsRestored(): void
    {
        $parent = $this->tempDirectory->subdirectory('stale-claim-restore');
        $retention = $this->retention($parent, maxCompletedRuns: 10);
        $this->completedRun($retention, 'run-claimed', 2, 'owned');
        \file_put_contents($parent . '/run-claimed/evidence.txt', 'changed content');
        $claim = $parent . '/.greenlight-prune-' . \str_repeat('b', 24);
        \rename($parent . '/run-claimed', $claim);

        $report = $retention->prune(now: 100);

        expect(\file_exists($claim))->toBeFalse();
        expect(\file_get_contents($parent . '/run-claimed/evidence.txt'))
            ->because('a prune MUST restore a claim that does not match its manifest')
            ->toBe('changed content');
        expect($report->warnings)->toBe([
            'Greenlight restored artifact run "run-claimed" after an interrupted prune.',
        ]);
    }

    #[Test]
    public function aDryRunReportsAStaleClaimWithoutAChange(): void
    {
        $parent = $this->tempDirectory->subdirectory('stale-claim-dry-run');
        $retention = $this->retention($parent, maxCompletedRuns: 10);
        $claim = $parent . '/.greenlight-prune-' . \str_repeat('c', 24);
        \mkdir($claim);

        $report = $retention->prune(dryRun: true, now: 100);

        expect(\is_dir($claim))->toBeTrue();
        expect($report->warnings)->toBe([
            \sprintf('Greenlight found the interrupted prune claim "%s". A prune without the dry-run option recovers it.', \basename($claim)),
        ]);
    }

    /** @return list<string> */
    private function claims(string $parent): array
    {
        $claims = \glob($parent . '/.greenlight-prune-*');

        return \is_array($claims) ? $claims : [];
    }

    private function retention(
        string $parent,
        ?int $maxCompletedRuns = null,
        ?int $maxCompletedRunAgeSeconds = null,
    ): ArtifactRetention {
        return ArtifactRetention::forConfiguration(new ArtifactConfiguration(
            directory: $parent,
            maxCompletedRuns: $maxCompletedRuns,
            maxCompletedRunAgeSeconds: $maxCompletedRunAgeSeconds,
        ), $parent);
    }

    private function completedRun(
        ArtifactRetention $retention,
        string $runId,
        int $completedAt,
        string $content,
        int $extraFiles = 0,
    ): void {
        $handle = $retention->begin($runId);
        \file_put_contents($handle->directory . '/evidence.txt', $content);
        for ($index = 0; $index < $extraFiles; ++$index) {
            \file_put_contents($handle->directory . '/extra-' . $index . '.txt', $content . $index);
        }
        $handle->complete();
        $this->rewriteMetadata($handle->directory, ['completedAt' => $completedAt]);
    }

    /** @param array<string, mixed> $changes */
    private function rewriteMetadata(string $directory, array $changes): void
    {
        $metadata = \json_decode(
            (string) \file_get_contents($directory . '/' . ArtifactRetention::METADATA_FILE),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        if (!\is_array($metadata)) {
            throw new \RuntimeException('The test did not decode artifact metadata.');
        }
        $map = [];
        foreach ($metadata as $key => $value) {
            $map[(string) $key] = $value;
        }
        ArtifactRetention::writeMetadata($directory, [...$map, ...$changes]);
    }
}
