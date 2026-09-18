<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Artifact\AttachmentKind;
use Greenlight\Artifact\StagedAttachment;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactStore;
use Greenlight\Execution\Artifact\TestArtifactBudget;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final readonly class ArtifactStoreIntegrityTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function workerSideStoresCannotPublishAttachments(): void
    {
        $root = $this->tempDirectory->subdirectory('worker-publication');
        $configuration = new ArtifactConfiguration($root);
        $owner = ArtifactStore::open($configuration, $root, 'run-1');
        $this->cleanup->defer($owner->cleanup(...));

        $id = new TestId('Example\EvidenceTest', 'fails');
        $attachments = $owner->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');
        $worker = ArtifactStore::fromSession($owner->session(), $configuration);

        expect()->calling(static fn(): TestResult => $worker->publish(new TestResult(
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
    }

    #[Test]
    public function workerSideCleanupKeepsCoordinatorStaging(): void
    {
        $root = $this->tempDirectory->subdirectory('worker-cleanup');
        $configuration = new ArtifactConfiguration($root);
        $owner = ArtifactStore::open($configuration, $root, 'run-worker-cleanup');
        $this->cleanup->defer($owner->cleanup(...));
        $id = new TestId('Example\EvidenceTest', 'keepsStaging');
        $attachments = $owner->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');
        $stagingDirectory = $owner->session()->stagingDirectory;
        $worker = ArtifactStore::fromSession($owner->session(), $configuration);

        $worker->cleanup();

        expect(\is_dir($stagingDirectory))
            ->because('only the orchestrator-owned store MAY remove shared staging')
            ->toBeTrue();
    }

    #[Test]
    public function workerSideCompletionDoesNotOwnRunRetention(): void
    {
        $root = $this->tempDirectory->subdirectory('worker-completion');
        $configuration = new ArtifactConfiguration($root);
        $owner = ArtifactStore::open($configuration, $root, 'run-worker-completion');
        $this->cleanup->defer($owner->cleanup(...));
        $worker = ArtifactStore::fromSession($owner->session(), $configuration);

        $report = $worker->complete();

        expect($report->items)->toBe([]);
        expect($report->warnings)->toBe([]);
    }

    #[Test]
    public function invalidCompletionMetadataMakesRetentionAdvisory(): void
    {
        $root = $this->tempDirectory->subdirectory('invalid-completion-metadata');
        $configuration = new ArtifactConfiguration($root, maxCompletedRuns: 1);
        $store = ArtifactStore::open($configuration, $root, 'run-invalid-completion');
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\\EvidenceTest', 'fails');
        $attachments = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attachments->text('evidence.txt', 'body');
        $store->publish(new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: $attachments->seal(),
        ));
        \file_put_contents($store->publicDirectory() . '/.greenlight-run.json', '{}');

        $report = $store->complete();

        expect($report->warnings)->toBe([
            'Greenlight did not complete artifact run metadata. This run is not eligible for pruning.',
        ]);
        expect(\is_dir($store->publicDirectory()))
            ->because('an incomplete ownership record MUST keep the current run directory')
            ->toBeTrue();
    }

    #[Test]
    public function unsafeStorageKeysCannotEscapeThePublicationDirectory(): void
    {
        $root = $this->tempDirectory->subdirectory('unsafe-storage-key');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-2');
        $this->cleanup->defer($store->cleanup(...));
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

        expect()->calling(static fn(): TestResult => $store->publish(new TestResult(
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
            );
        expect(\file_exists($root . '/escaped.txt'))
            ->toBeFalse();
    }
}
