<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Worker;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\RetryPolicy;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Runner\Artifact\ArtifactStore;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Runner\Worker\RetryAttachmentTest;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class RetryDeciderAttachmentTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function retryDecidersReceiveAttemptAttachmentMetadata(): void
    {
        $root = $this->tempDirectory->subdirectory('retry-decider-attachments');
        $store = ArtifactStore::open(new ArtifactConfiguration($root), $root, 'run-1');
        $this->cleanup->defer($store->cleanup(...));
        $decider = new class implements RetryDecider, Fake {
            /** @var list<TestResult> */
            public array $results = [];

            public function shouldRetry(
                RetryPolicy $policy,
                TestResult $result,
                int $attempt,
                ?\Throwable $cause,
            ): bool {
                $this->results[] = $result;

                return false;
            }
        };
        $plan = new ExecutionPlan([
            PlanEntryFixture::create(RetryAttachmentTest::class, 'failsWithEvidence'),
        ]);
        $sink = new CollectingEventSink();

        new Worker(
            new HarnessRegistry([]),
            PluginRegistry::forWorker([$decider]),
            artifactStore: $store,
        )->run($plan, $sink);

        Expect::that($decider->results)
            ->because('a retry decider MUST receive the unsuccessful attempt result')
            ->toHaveCount(1);
        Expect::that($decider->results[0]->attachments)
            ->because('a retry decider MUST receive attachment metadata before it decides')
            ->toHaveCount(1);
        Expect::that($decider->results[0]->attachments[0]->name)
            ->toBe('failure.txt');
        Expect::that($decider->results[0]->attachments[0]->sizeBytes)
            ->toBe(14);
    }
}
