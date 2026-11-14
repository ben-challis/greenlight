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

    private function completedRun(ArtifactRetention $retention, string $runId, int $completedAt, string $content): void
    {
        $handle = $retention->begin($runId);
        \file_put_contents($handle->directory . '/evidence.txt', $content);
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
