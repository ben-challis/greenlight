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

final readonly class ArtifactTestDirectoryCollisionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function testIdsWithTheSameSlugKeepDistinctEvidence(): void
    {
        $root = $this->tempDirectory->subdirectory('test-directory-collision');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $spaced = new TestId('Example\EvidenceTest', 'usesData', 'a b');
        $hyphenated = new TestId('Example\EvidenceTest', 'usesData', 'a-b');

        try {
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
                ->toHaveCount(1)
                ->and($second->attachments)
                ->toHaveCount(1)
                ->and($first->attachments[0]->path)
                ->not()
                ->toBe($second->attachments[0]->path)
                ->and((string) \file_get_contents($first->attachments[0]->path))
                ->toBe('spaced data-set key')
                ->and((string) \file_get_contents($second->attachments[0]->path))
                ->toBe('hyphenated data-set key');
        } finally {
            $store->cleanup();
        }
    }
}
