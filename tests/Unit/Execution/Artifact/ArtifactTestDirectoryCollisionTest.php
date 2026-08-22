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

final readonly class ArtifactTestDirectoryCollisionTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function testIdsWithTheSameSlugKeepDistinctEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('test-directory-collision');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));
        $spaced = new TestId('Example\EvidenceTest', 'usesData', 'a b');
        $hyphenated = new TestId('Example\EvidenceTest', 'usesData', 'a-b');

        $firstAttempt = $store->forAttempt($spaced, 1, new TestArtifactBudget());
        $firstAttempt->text('evidence.txt', 'spaced data-set key');
        $first = $store->publish(new TestResult(
            $spaced,
            Outcome::Failed,
            0.1,
            0,
            attachments: $firstAttempt->seal(),
        ));

        $secondAttempt = $store->forAttempt($hyphenated, 1, new TestArtifactBudget());
        $secondAttempt->text('evidence.txt', 'hyphenated data-set key');
        $second = $store->publish(new TestResult(
            $hyphenated,
            Outcome::Failed,
            0.1,
            0,
            attachments: $secondAttempt->seal(),
        ));

        Expect::that($first->attachments)
            ->because('test IDs with the same filesystem slug MUST keep distinct evidence')
            ->toHaveCount(1);
        Expect::that($second->attachments)
            ->toHaveCount(1);
        Expect::that($first->attachments[0]->path)
            ->not()
            ->toBe($second->attachments[0]->path);
        Expect::that((string) \file_get_contents($first->attachments[0]->path))
            ->toBe('spaced data-set key');
        Expect::that((string) \file_get_contents($second->attachments[0]->path))
            ->toBe('hyphenated data-set key');
    }
}
