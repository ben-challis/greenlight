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

final readonly class ArtifactRecoveryAttemptFloorTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aStaleAttemptMarkerDoesNotReduceTheResultAttempt(): void
    {
        $root = $this->tempDirectory->subdirectory('recovery-attempt-floor');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-attempt-floor');
        $id = new TestId('Example\\EvidenceTest', 'crashesAfterReporting');

        try {
            $attachments = $store->forAttempt($id, 2, new TestArtifactBudget());
            $attachments->text('evidence.txt', 'completed evidence');

            $recovered = $store->recover(new TestResult(
                $id,
                Outcome::Errored,
                0.0,
                0,
                attempts: 10,
            ));

            Expect::that($recovered->attempts)
                ->because('stale recovery metadata MUST NOT reduce a reported attempt count')
                ->toBe(10);
            Expect::that($recovered->attachments)
                ->toHaveCount(1);
            Expect::that($recovered->attachments[0]->name)
                ->toBe('evidence.txt');
            Expect::that((string) \file_get_contents($recovered->attachments[0]->path))
                ->toBe('completed evidence');
        } finally {
            $store->cleanup();
        }
    }
}
