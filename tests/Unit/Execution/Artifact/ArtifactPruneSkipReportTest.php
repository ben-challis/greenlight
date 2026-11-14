<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

use function Greenlight\expect;

final readonly class ArtifactPruneSkipReportTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function skippedDirectoriesReportTheirReason(): void
    {
        $parent = $this->tempDirectory->subdirectory('skip-reasons');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        $retention->begin('run-aborted')->close();
        $this->completedRun($retention, 'run-finder', 2, 'owned');
        \file_put_contents($parent . '/run-finder/.DS_Store', 'finder');
        $held = $retention->begin('run-held');
        $this->cleanup->defer($held->close(...));

        $report = $retention->prune(dryRun: true, now: 100);

        expect($report->items)->toBe([]);
        expect($report->warnings)
            ->because('a dry run MUST explain each run directory that it does not select')
            ->toBe([
                'Greenlight skipped artifact directory "run-aborted" because the run did not complete and no process holds it.',
                'Greenlight skipped artifact directory "run-finder" because its content changed after completion.',
            ]);
    }

    #[Test]
    public function automaticRetentionDoesNotReportSkippedDirectories(): void
    {
        $parent = $this->tempDirectory->subdirectory('skip-reasons-automatic');
        $this->retention($parent, maxCompletedRunAgeSeconds: 1)->begin('run-aborted')->close();
        $store = ArtifactStore::open(new ArtifactConfiguration($parent, maxCompletedRunAgeSeconds: 1), $parent, 'run-current');
        $this->cleanup->defer($store->cleanup(...));

        $report = $store->complete();

        expect($report->warnings)
            ->because('automatic retention after each run MUST NOT repeat a warning for each skipped directory')
            ->toBe([]);
        expect(\is_dir($parent . '/run-aborted'))->toBeTrue();
    }

    #[Test]
    public function anAbandonedRunWithAnEmptyDirectoryIsPruned(): void
    {
        $parent = $this->tempDirectory->subdirectory('abandoned-empty-directory');
        $retention = $this->retention($parent);
        $abandoned = $retention->begin('run-abandoned');
        \mkdir($abandoned->directory . '/Example-Test-0123456789ab');
        $abandoned->close();
        $this->rewriteMetadata($parent . '/run-abandoned', ['startedAt' => 10]);

        $report = $retention->prune(now: 100, abandonedRunAgeSeconds: 50);

        expect(\array_map(static fn($item): string => $item->runId, $report->items))
            ->because('a failed publication can leave an empty directory in an abandoned run')
            ->toBe(['run-abandoned']);
        expect(\file_exists($parent . '/run-abandoned'))->toBeFalse();
    }

    #[Test]
    public function anExplicitAgePrunesAbandonedActiveRuns(): void
    {
        $parent = $this->tempDirectory->subdirectory('abandoned-runs');
        $retention = $this->retention($parent);
        $abandoned = $retention->begin('run-abandoned');
        \file_put_contents($abandoned->directory . '/evidence.txt', 'owned');
        $abandoned->close();
        $this->rewriteMetadata($parent . '/run-abandoned', ['startedAt' => 10]);
        $recent = $retention->begin('run-recent');
        $recent->close();
        $this->rewriteMetadata($parent . '/run-recent', ['startedAt' => 95]);
        $held = $retention->begin('run-held');
        $this->cleanup->defer($held->close(...));
        $this->rewriteMetadata($parent . '/run-held', ['startedAt' => 10]);

        $withoutOptIn = $retention->prune(now: 100);
        $report = $retention->prune(now: 100, abandonedRunAgeSeconds: 50);

        expect($withoutOptIn->items)->toBe([]);
        expect(\array_map(static fn($item): array => [$item->runId, $item->reasons], $report->items))
            ->because('the explicit option MUST select only old active runs that no process holds')
            ->toBe([['run-abandoned', ['abandoned']]]);
        expect(\file_exists($parent . '/run-abandoned'))->toBeFalse();
        expect(\is_dir($parent . '/run-recent'))->toBeTrue();
        expect(\is_dir($parent . '/run-held'))->toBeTrue();
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
