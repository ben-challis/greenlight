<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Artifact\TestArtifactBudget;

final readonly class ArtifactRecoveryOrderTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function recoveredAttachmentsUseAttemptOrder(): void
    {
        $root = $this->tempDirectory->subdirectory('recovery-order');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-order');
        $this->cleanup->defer($store->cleanup(...));
        $id = new TestId('Example\EvidenceTest', 'crashesOnRetry');
        $budget = new TestArtifactBudget();

        $tenth = $store->forAttempt($id, 10, $budget);
        $tenth->text('tenth.txt', 'attempt ten');
        $second = $store->forAttempt($id, 2, $budget);
        $second->text('second.txt', 'attempt two');

        $recovered = $store->recover(new TestResult(
            $id,
            Outcome::Errored,
            0.0,
            0,
            attempts: 10,
        ));

        Expect::that($recovered->attachments)
            ->because('crash recovery orders evidence by numeric attempt')
            ->toHaveCount(2);
        Expect::that(\array_map(
            static fn($attachment): array => [$attachment->attempt, $attachment->name],
            $recovered->attachments,
        ))
            ->toBe([
                [2, 'second.txt'],
                [10, 'tenth.txt'],
            ]);
    }
}
