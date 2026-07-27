<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\Artifact\AttachmentKind;
use Greenlight\Core\Artifact\StagedAttachment;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactStoreIntegrityTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function workerSideStoresCannotPublishAttachments(): void
    {
        $root = $this->tempDirectory->subdirectory('worker-publication');
        $configuration = new ArtifactConfiguration($root);
        $owner = ArtifactStore::open($configuration, $root, 'run-1');

        try {
            $id = new TestId('Example\EvidenceTest', 'fails');
            $attachments = $owner->forAttempt($id, 1, new TestArtifactBudget());
            $attachments->text('evidence.txt', 'body');
            $worker = ArtifactStore::fromSession($owner->session(), $configuration);

            Expect::that(static fn(): TestResult => $worker->publish(new TestResult(
                $id,
                Outcome::Failed,
                0.1,
                0,
                attachments: $attachments->seal(),
            )))
                ->because('only the orchestrator-owned store MAY publish attachments')
                ->toThrow(
                    AttachmentError::class,
                    message: 'A worker attempted to publish attachments.',
                );
        } finally {
            $owner->cleanup();
        }
    }

    #[Test]
    public function unsafeStorageKeysCannotEscapeThePublicationDirectory(): void
    {
        $root = $this->tempDirectory->subdirectory('unsafe-storage-key');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-2');
        $id = new TestId('Example\EvidenceTest', 'fails');
        $forged = new StagedAttachment(
            'evidence.txt',
            AttachmentKind::Text,
            'text/plain',
            4,
            \hash('sha256', 'body'),
            1,
            $store->publicDirectory() . '/../escaped.txt',
            storageKey: '../escaped.txt',
        );

        try {
            Expect::that(static fn(): TestResult => $store->publish(new TestResult(
                $id,
                Outcome::Failed,
                0.1,
                0,
                attachments: [$forged],
            )))
                ->because('attachment storage keys MUST stay inside the publication directory')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Attachment metadata contains an unsafe storage key.',
                )
                ->and(\file_exists($root . '/escaped.txt'))
                ->toBeFalse();
        } finally {
            $store->cleanup();
        }
    }
}
