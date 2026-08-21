<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\FilesystemRestriction;

final readonly class ArtifactOutputSafetyTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function outputDirectoriesRejectNullBytesBeforeFilesystemUse(): void
    {
        $workingDirectory = $this->tempDirectory->subdirectory('invalid-output');

        Expect::that(static fn(): ArtifactStore => ArtifactStore::open(
            new ArtifactConfiguration("published\0outside"),
            $workingDirectory,
            'run-1',
        ))
            ->because('an attachment output directory MUST be a valid filesystem path')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment output directory is invalid.',
            );
    }

    #[Test]
    #[Isolated]
    public function restrictedPathsFallBackWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $restricted = \dirname($root);
        FilesystemRestriction::toProject($root);

        $absoluteStore = ErrorTrap::run(
            static fn() => ArtifactStore::open(
                new ArtifactConfiguration($restricted),
                $root,
                'run-output',
            ),
            $absoluteWarning,
        );
        $workingDirectoryStore = ErrorTrap::run(
            static fn() => ArtifactStore::open(
                new ArtifactConfiguration('artifacts'),
                $restricted,
                'run-working-directory',
            ),
            $workingDirectoryWarning,
        );

        Expect::that($absoluteStore->publicDirectory())
            ->because('a restricted absolute output directory MUST keep its configured path')
            ->toBe($restricted . '/run-output');
        Expect::that($workingDirectoryStore->publicDirectory())
            ->because('a restricted working directory MUST keep its configured path')
            ->toBe($restricted . '/artifacts/run-working-directory');
        Expect::that([$absoluteWarning, $workingDirectoryWarning])
            ->because('restricted artifact paths MUST not leak engine diagnostics')
            ->toBe([null, null]);
    }

    #[Test]
    public function outputDirectorySymlinksCannotRedirectPublication(): void
    {
        $root = $this->tempDirectory->subdirectory('output-symlink');
        $outside = $this->tempDirectory->subdirectory('outside-output');
        \mkdir($root . '/published');
        [$store, $id, $staged] = $this->stageEvidence($root, $root . '/published');
        $this->cleanup->defer($store->cleanup(...));
        $firstSegment = \explode('/', $staged->storageKey)[0];
        \mkdir($store->publicDirectory(), 0o777, true);
        \symlink($outside, $store->publicDirectory() . '/' . $firstSegment);

        Expect::that(static fn(): TestResult => $store->publish(new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        )))
            ->because('artifact publication MUST NOT follow output directory symbolic links')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment output directory contains a symbolic link.',
            );
        Expect::that(\glob($outside . '/*'))
            ->because('a rejected publication MUST NOT write outside its output directory')
            ->toBe([]);
        Expect::that(\is_file($store->session()->stagingDirectory . '/' . $staged->storageKey))
            ->because('rejected evidence remains available for recovery')
            ->toBeTrue();
    }

    #[Test]
    public function nonDirectoryOutputEntriesBlockPublication(): void
    {
        $root = $this->tempDirectory->subdirectory('output-entry');
        \mkdir($root . '/published');
        [$store, $id, $staged] = $this->stageEvidence($root, $root . '/published');
        $this->cleanup->defer($store->cleanup(...));
        $firstSegment = \explode('/', $staged->storageKey)[0];
        \mkdir($store->publicDirectory(), 0o777, true);
        $blocker = $store->publicDirectory() . '/' . $firstSegment;
        \file_put_contents($blocker, 'keep');

        Expect::that(static fn(): TestResult => $store->publish(new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        )))
            ->because('artifact publication requires directory-only output path segments')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment output path contains a non-directory entry.',
            );
        Expect::that((string) \file_get_contents($blocker))
            ->because('a rejected publication MUST NOT replace the blocking entry')
            ->toBe('keep');
        Expect::that(\is_file($store->session()->stagingDirectory . '/' . $staged->storageKey))
            ->because('rejected evidence remains available for recovery')
            ->toBeTrue();
    }

    #[Test]
    public function anUnwritableOutputParentPreservesStagedEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('output-permission');
        $readOnly = $root . '/read-only';
        \mkdir($readOnly, 0o700);
        \chmod($readOnly, 0o500);
        \clearstatcache(true, $readOnly);

        if (\is_writable($readOnly)) {
            \chmod($readOnly, 0o700);
            throw new SkipTest('The filesystem does not enforce directory write permissions.');
        }

        [$store, $id, $staged] = $this->stageEvidence($root, $readOnly . '/artifacts');
        $this->cleanup->defer($store->cleanup(...));
        $this->cleanup->defer(static fn(): bool => \chmod($readOnly, 0o700));

        Expect::that(static fn(): TestResult => $store->publish(new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        )))
            ->because('an unwritable output parent MUST reject attachment publication')
            ->toThrow(
                AttachmentError::class,
                matching: '/^Failed to create attachment output directory/',
            );
        Expect::that(\is_file($store->session()->stagingDirectory . '/' . $staged->storageKey))
            ->because('rejected evidence MUST remain available for recovery')
            ->toBeTrue();
    }

    /**
     * @return array{ArtifactStore, TestId, StagedAttachment}
     */
    private function stageEvidence(string $workingDirectory, string $outputDirectory): array
    {
        $store = ArtifactStore::open(
            new ArtifactConfiguration($outputDirectory),
            $workingDirectory,
            'run-1',
        );
        $id = new TestId('Example\EvidenceTest', 'publishesEvidence');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');

        return [$store, $id, $attachments->seal()[0]];
    }
}
