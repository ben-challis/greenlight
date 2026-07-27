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

final readonly class ArtifactStorePublicationTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function publishedMetadataCannotBePublishedAgain(): void
    {
        $root = $this->tempDirectory->subdirectory('published-metadata');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-plain-metadata');
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'evidence');
        $attachment = $attempt->seal()[0]->published();
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: [$attachment],
        );

        try {
            Expect::that(static fn(): TestResult => $store->publish($result))
                ->because('published attachment metadata has no staging coordinate')
                ->toThrow(
                    AttachmentError::class,
                    message: 'Attachment metadata does not contain a staging coordinate.',
                );
        } finally {
            $store->cleanup();
        }
    }

    #[Test]
    public function workerStoresCannotPublishCoordinatorOwnedEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('worker-publication');
        $configuration = new ArtifactConfiguration($root);
        $coordinator = ArtifactStore::open($configuration, $root, 'run-worker-publication');
        $worker = ArtifactStore::fromSession($coordinator->session(), $configuration);
        $id = new TestId('Example\EvidenceTest', 'fails');
        $attempt = $worker->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'evidence');
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: $attempt->seal(),
        );

        try {
            Expect::that(static fn(): TestResult => $worker->publish($result))
                ->because('only the coordinator can publish attachment evidence')
                ->toThrow(
                    AttachmentError::class,
                    message: 'A worker attempted to publish attachments.',
                );

            $published = $coordinator->publish($result);

            Expect::that($published->attachments)
                ->because('a rejected worker publication leaves the evidence intact')
                ->toHaveCount(1);
        } finally {
            $coordinator->cleanup();
        }
    }
}
