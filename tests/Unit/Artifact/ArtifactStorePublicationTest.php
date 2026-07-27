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
}
