<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactTransformedOutcomeRetentionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function passingTransformationsRetainEvidenceFromTheFailedOutcome(): void
    {
        $root = $this->tempDirectory->subdirectory('transformed-retention');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $id = new TestId('Example\EvidenceTest', 'quarantinedFailure');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('failure.txt', 'original failure evidence');
        $result = new TestResult(
            $id,
            Outcome::Failed,
            0.1,
            0,
            attachments: $attempt->seal(),
        )->withOutcome(Outcome::Passed, 'quarantine-plugin');

        try {
            $published = $store->publish($result);

            Expect::that($published->attachments)
                ->because('a passing transformation MUST retain evidence from its failed source')
                ->toHaveCount(1)
                ->and($published->attachments[0]->name)
                ->toBe('failure.txt')
                ->and((string) \file_get_contents($published->attachments[0]->path))
                ->toBe('original failure evidence');
        } finally {
            $store->cleanup();
        }
    }

    #[Test]
    public function successfulTransformationsDoNotRetainOnFailureEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('successful-transformation');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-2');
        $id = new TestId('Example\EvidenceTest', 'quarantinedPass');
        $attempt = $store->forAttempt($id, 1, new TestArtifactBudget());
        $attempt->text('passing.txt', 'successful evidence');
        $result = new TestResult(
            $id,
            Outcome::Passed,
            0.1,
            0,
            attachments: $attempt->seal(),
        )->withOutcome(Outcome::Skipped, 'quarantine-plugin');

        try {
            $published = $store->publish($result);

            Expect::that($published->attachments)
                ->because('a transformation between successful outcomes MUST discard on-failure evidence')
                ->toBe([])
                ->and(\file_exists($store->publicDirectory()))
                ->toBeFalse();
        } finally {
            $store->cleanup();
        }
    }
}
