<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;
use Greenlight\Execution\Artifact\NativeFileCopier;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class ArtifactContentVerificationTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aFailedClaimVerificationRestoresTheRun(): void
    {
        $parent = $this->tempDirectory->subdirectory('claim-verification-failure');
        $retention = $this->retention($parent, maxCompletedRunAgeSeconds: 1);
        $this->completedRun($retention, 'run-unreadable', 2, 'owned');
        \chmod($parent . '/run-unreadable/evidence.txt', 0o000);

        try {
            $report = $retention->prune(now: 100);
        } finally {
            \chmod($parent . '/run-unreadable/evidence.txt', 0o600);
        }

        expect($report->items)->toBe([]);
        expect($report->warnings)
            ->because('a verification failure after the claim MUST restore the run and report it')
            ->toBe(['Greenlight did not prune artifact run "run-unreadable".']);
        expect(\is_file($parent . '/run-unreadable/evidence.txt'))->toBeTrue();
        expect(\glob($parent . '/.greenlight-prune-*'))->toBe([]);
    }

    #[Test]
    public function selectionDoesNotReadRetainedContent(): void
    {
        $parent = $this->tempDirectory->subdirectory('selection-without-content');
        $retention = $this->retention($parent, maxCompletedRuns: 1);
        $this->completedRun($retention, 'run-old', 10, 'old');
        $this->completedRun($retention, 'run-new', 20, 'new');
        \chmod($parent . '/run-old/evidence.txt', 0o000);

        try {
            $report = $retention->prune(dryRun: true, now: 30);
        } finally {
            \chmod($parent . '/run-old/evidence.txt', 0o600);
        }

        expect(\array_map(static fn($item): string => $item->runId, $report->items))
            ->because('selection MUST use metadata and file sizes, not file content')
            ->toBe(['run-old']);
    }

    #[Test]
    public function completionReusesRecordedDigests(): void
    {
        $parent = $this->tempDirectory->subdirectory('recorded-digests');
        $handle = $this->retention($parent, maxCompletedRuns: 1)->begin('run-1');
        \file_put_contents($handle->directory . '/evidence.txt', 'owned');
        $recorded = \str_repeat('f', 64);
        $handle->recordPublished('evidence.txt', 5, $recorded);
        $handle->complete();

        $metadata = \json_decode(
            (string) \file_get_contents($handle->directory . '/' . ArtifactRetention::METADATA_FILE),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        expect($metadata)->toContainSubset(['files' => ['evidence.txt' => ['bytes' => 5, 'sha256' => $recorded]]]);
    }

    #[Test]
    public function aRejectedRunLetsTheLimitSelectANewerRun(): void
    {
        $parent = $this->tempDirectory->subdirectory('rejected-run-reselection');
        $retention = $this->retention($parent, maxCompletedRuns: 1);
        $this->completedRun($retention, 'run-changed', 10, 'old');
        $this->completedRun($retention, 'run-middle', 20, 'mid');
        $this->completedRun($retention, 'run-newest', 30, 'new');
        \file_put_contents($parent . '/run-changed/evidence.txt', 'odd');

        $report = $retention->prune(now: 40);

        expect(\array_map(static fn($item): string => $item->runId, $report->items))
            ->because('a run that fails verification MUST NOT stop the limit from selecting another run')
            ->toBe(['run-middle']);
        expect($report->warnings)->toBe([
            'Greenlight did not prune artifact run "run-changed" because its content does not match its completion manifest.',
        ]);
        expect(\file_get_contents($parent . '/run-changed/evidence.txt'))->toBe('odd');
        expect(\is_dir($parent . '/run-newest'))->toBeTrue();
    }

    #[Test]
    public function completionReadsAFileThatChangedAfterPublication(): void
    {
        $parent = $this->tempDirectory->subdirectory('recorded-digest-changed');
        $handle = $this->retention($parent, maxCompletedRuns: 1)->begin('run-1');
        $path = $handle->directory . '/evidence.txt';
        \file_put_contents($path, 'owned');
        $handle->recordPublished('evidence.txt', 5, \str_repeat('f', 64));
        \file_put_contents($path, 'edits');
        \touch($path, (int) \filemtime($path) + 10);
        $handle->complete();

        $metadata = \json_decode(
            (string) \file_get_contents($handle->directory . '/' . ArtifactRetention::METADATA_FILE),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        expect($metadata)
            ->because('a file with a new modification time MUST get a new digest')
            ->toContainSubset(['files' => ['evidence.txt' => ['bytes' => 5, 'sha256' => \hash('sha256', 'edits')]]]);
    }

    #[Test]
    public function aLinkedStagedFileIsCopiedNotMoved(): void
    {
        $directory = $this->tempDirectory->subdirectory('linked-staged-file');
        \file_put_contents($directory . '/target.txt', 'evidence');
        \symlink($directory . '/target.txt', $directory . '/source.txt');

        $digest = new NativeFileCopier()->copy($directory . '/source.txt', $directory . '/destination.txt');

        expect($digest)->toBe(\hash('sha256', 'evidence'));
        expect(\is_link($directory . '/destination.txt'))
            ->because('publication MUST NOT move a symbolic link into the run directory')
            ->toBeFalse();
        expect(\is_file($directory . '/destination.txt'))->toBeTrue();
    }

    #[Test]
    public function aSourceThatCannotBeLinkedIsCopiedWithItsDigest(): void
    {
        $destination = $this->tempDirectory->subdirectory('copy-digest') . '/destination.txt';

        $digest = new NativeFileCopier()->copy('data://text/plain,evidence', $destination);

        expect($digest)
            ->because('a copy MUST calculate the digest from the bytes that it writes')
            ->toBe(\hash('sha256', 'evidence'));
        expect(\file_get_contents($destination))->toBe('evidence');
    }

    #[Test]
    public function aSameDeviceSourceIsLinkedAndKept(): void
    {
        $directory = $this->tempDirectory->subdirectory('link-digest');
        \file_put_contents($directory . '/source.txt', 'evidence');

        $digest = new NativeFileCopier()->copy($directory . '/source.txt', $directory . '/destination.txt');

        expect($digest)->toBe(\hash('sha256', 'evidence'));
        expect(\file_get_contents($directory . '/source.txt'))
            ->because('the staged file MUST stay until publication succeeds')
            ->toBe('evidence');
        expect(\fileinode($directory . '/destination.txt'))
            ->because('a source on the same device MUST be linked, not copied')
            ->toBe(\fileinode($directory . '/source.txt'));
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
