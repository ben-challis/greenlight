<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\Attachment;
use Greenlight\Artifact\AttachmentError;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Expect\Expect;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

final readonly class ArtifactStorePublicationTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function publishedMetadataCannotBePublishedAgain(): void
    {
        $root = $this->tempDirectory->subdirectory('published-metadata');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-plain-metadata');
        $this->cleanup->defer($store->cleanup(...));
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

        Expect::that(static fn(): TestResult => $store->publish($result))
            ->because('published attachment metadata has no staging coordinate')
            ->toThrow(
                AttachmentError::class,
                message: 'Attachment metadata does not contain a staging coordinate.',
            );
    }

    #[Test]
    public function workerStoresCannotPublishCoordinatorOwnedEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('worker-publication');
        $configuration = new ArtifactConfiguration($root);
        $coordinator = ArtifactStore::open($configuration, $root, 'run-worker-publication');
        $this->cleanup->defer($coordinator->cleanup(...));
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
    }

    #[Test]
    public function aRetentionCallbackControlsFinalPublication(): void
    {
        $root = $this->tempDirectory->subdirectory('plugin-retention');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-plugin-retention',
            retainAttachment: static fn(TestResult $result, Attachment $attachment): bool => false,
        );
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\\EvidenceTest', 'fails');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'evidence');
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: $attempt->seal(),
        );

        $published = $store->publish($result);

        Expect::that($published->attachments)->toBe([]);
        Expect::that(\file_exists($store->publicDirectory()))->toBeFalse();
    }

    #[Test]
    public function aRetentionCallbackFailureKeepsItsCause(): void
    {
        $root = $this->tempDirectory->subdirectory('plugin-retention-failure');
        $failure = new \RuntimeException('Retention decision failed');
        $store = ArtifactStore::open(
            new ArtifactConfiguration($root),
            $root,
            'run-plugin-retention-failure',
            retainAttachment: static fn(TestResult $result, Attachment $attachment): bool => throw $failure,
        );
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\\EvidenceTest', 'fails');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('evidence.txt', 'evidence');
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: $attempt->seal(),
        );

        Expect::that(static fn(): TestResult => $store->publish($result))
            ->because('attachment publication MUST contain a retention callback failure')
            ->toThrow(
                static function (AttachmentError $error) use ($failure): void {
                    Expect::that($error->getMessage())->toBe('Retention decision failed');
                    Expect::that($error->getPrevious())->toBe($failure);
                },
            );
    }
}
