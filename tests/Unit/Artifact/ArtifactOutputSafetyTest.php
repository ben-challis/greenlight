<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactOutputSafetyTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

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
    public function outputDirectorySymlinksCannotRedirectPublication(): void
    {
        $root = $this->tempDirectory->subdirectory('output-symlink');
        $outside = $this->tempDirectory->subdirectory('outside-output');
        \mkdir($root . '/published');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root . '/published'),
            $root,
            'run-1',
        );
        $id = new TestId('Example\EvidenceTest', 'publishesEvidence');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');
        $staged = $attachments->seal()[0];
        $firstSegment = \explode('/', $staged->storageKey)[0];
        \mkdir($store->publicDirectory(), 0o777, true);
        \symlink($outside, $store->publicDirectory() . '/' . $firstSegment);

        try {
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
        } finally {
            $store->cleanup();
        }
    }
}
