<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

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

final readonly class ArtifactTransformedOutcomeRetentionTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function passingTransformationsRetainEvidenceFromTheFailedOutcome(): void
    {
        $root = $this->tempDirectory->subdirectory('transformed-retention');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));
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

        $published = $store->publish($result);

        Expect::that($published->attachments)
            ->because('a passing transformation MUST retain evidence from its failed source')
            ->toHaveCount(1);
        Expect::that($published->attachments[0]->name)
            ->toBe('failure.txt');
        Expect::that((string) \file_get_contents($published->attachments[0]->path))
            ->toBe('original failure evidence');
    }

    #[Test]
    public function successfulTransformationsDoNotRetainOnFailureEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('successful-transformation');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-2');
        $this->cleanup->defer($store->cleanup(...));
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

        $published = $store->publish($result);

        Expect::that($published->attachments)
            ->because('a transformation between successful outcomes MUST discard on-failure evidence')
            ->toBe([]);
        Expect::that(\file_exists($store->publicDirectory()))
            ->toBeFalse();
    }
}
