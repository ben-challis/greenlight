<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\AttachmentRetention;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactSession;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;
use Greenlight\Tests\Fixture\Artifact\ControlledFileRenamer;

final readonly class ArtifactRenameFailureTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function byteStagingRenameFailureRollsBackTheFileAndQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('byte-staging-rename-failure');
        [$store, $configuration, $renamer] = $this->store($root);
        $storageKey = 'Example-EvidenceTest/attempt-1/01-evidence.txt';
        $path = $store->session()->stagingDirectory . '/' . $storageKey;
        $renamer->failNext = true;

        Expect::that(static fn() => $store->stageBytes(
            'evidence',
            'evidence.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a failed byte staging rename MUST reject the attachment')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to finalize attachment staging file.',
            )
            ->and(\file_exists($path))
            ->because('a failed byte staging rename MUST remove the final path')
            ->toBeFalse()
            ->and(\file_exists($path . '.part'))
            ->because('a failed byte staging rename MUST remove the partial path')
            ->toBeFalse();

        $staged = $store->stageBytes(
            'evidence',
            'evidence.txt',
            $storageKey,
            'text/plain',
            AttachmentKind::Text,
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        Expect::that($staged->name)
            ->because('a failed byte staging rename MUST release its run quota')
            ->toBe('evidence.txt');
    }

    #[Test]
    public function fileStagingRenameFailureRollsBackTheFileAndQuota(): void
    {
        $root = $this->tempDirectory->subdirectory('file-staging-rename-failure');
        $source = $root . '/source.txt';
        \file_put_contents($source, 'evidence');
        [$store, $configuration, $renamer] = $this->store($root);
        $storageKey = 'Example-EvidenceTest/attempt-1/01-evidence.txt';
        $path = $store->session()->stagingDirectory . '/' . $storageKey;
        $renamer->failNext = true;

        Expect::that(static fn() => $store->stageFile(
            $source,
            'evidence.txt',
            $storageKey,
            'text/plain',
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        ))
            ->because('a failed file staging rename MUST reject the attachment')
            ->toThrow(
                AttachmentError::class,
                message: 'Failed to finalize attachment staging file.',
            )
            ->and(\file_exists($path))
            ->because('a failed file staging rename MUST remove the final path')
            ->toBeFalse()
            ->and(\file_exists($path . '.part'))
            ->because('a failed file staging rename MUST remove the partial path')
            ->toBeFalse();

        $staged = $store->stageFile(
            $source,
            'evidence.txt',
            $storageKey,
            'text/plain',
            1,
            AttachmentRetention::OnFailure,
            $configuration,
        );

        Expect::that($staged->name)
            ->because('a failed file staging rename MUST release its run quota')
            ->toBe('evidence.txt');
    }

    #[Test]
    public function publicationRenameFailureRemovesThePartialFileAndPreservesStaging(): void
    {
        $root = $this->tempDirectory->subdirectory('publication-rename-failure');
        $renamer = new ControlledFileRenamer();
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-publication-rename-failure',
            fileRenamer: $renamer,
        );
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'evidence');
        $staged = $attempt->seal()[0];
        $source = $store->session()->stagingDirectory . '/' . $staged->storageKey;
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$staged],
        );
        $renamer->failNext = true;

        try {
            Expect::that(static fn(): TestResult => $store->publish($result))
                ->because('a failed publication rename MUST reject the attachment')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Failed to publish attachment.',
                )
                ->and(\file_exists($staged->path))
                ->because('a failed publication rename MUST NOT leave the final attachment')
                ->toBeFalse()
                ->and(\glob($staged->path . '.part-*'))
                ->because('a failed publication rename MUST remove the partial attachment')
                ->toBe([])
                ->and(\is_file($source))
                ->because('a failed publication rename MUST preserve staging for a retry')
                ->toBeTrue();

            $published = $store->publish($result);

            Expect::that($published->attachments)
                ->because('publication MUST succeed after a transient rename failure')
                ->toHaveCount(1);
        } finally {
            $store->cleanup();
        }
    }

    /**
     * @return array{ArtifactStore, ArtifactConfiguration, ControlledFileRenamer}
     */
    private function store(string $root): array
    {
        $configuration = new ArtifactConfiguration(
            $root . '/published',
            maxRunAttachments: 1,
        );
        $renamer = new ControlledFileRenamer();
        $store = ArtifactStore::fromSession(
            new ArtifactSession($root . '/staging', $root . '/published/run-1'),
            $configuration,
            fileRenamer: $renamer,
        );

        return [$store, $configuration, $renamer];
    }
}
