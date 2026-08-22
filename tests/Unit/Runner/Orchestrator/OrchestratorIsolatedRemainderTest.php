<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\LeakSuite\CleanTest;
use Greenlight\Tests\Fixture\Runner\Orchestrator\RecycleBeforeIsolatedWorker;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\NativeOrchestrator;
use Greenlight\Tests\Support\PlanEntryFixture;

final class OrchestratorIsolatedRemainderTest
{
    #[Test]
    #[Timeout(30.0)]
    public function aRecyclingWorkerRequeuesItsUnstartedIsolatedTest(): void
    {
        $root = \dirname(__DIR__, 4);
        $bootstrap = \sprintf(
            'require %s; exit(%s::run($argv[2], $argv[3], $argv[4]));',
            \var_export($root . '/vendor/autoload.php', true),
            RecycleBeforeIsolatedWorker::class,
        );
        $orchestrator = NativeOrchestrator::create(
            workerCommand: [\PHP_BINARY, '-r', $bootstrap],
            workingDirectory: $root,
        );
        $sink = new CollectingEventSink();
        $plan = new ExecutionPlan([
            PlanEntryFixture::create(CleanTest::class, 'passesAndIsCollectable', isolated: true),
        ]);

        $summary = $orchestrator->run($plan, $sink, 1);
        $workers = [];

        foreach ($sink->events as $event) {
            if ($event instanceof TestClassStarted) {
                $workers[] = $event->workerId;
            }
        }

        Expect::that($summary)
            ->because('the replacement worker MUST execute the requeued isolated test')
            ->toEqual(new ResultSummary(passed: 1));
        Expect::that($workers)
            ->toBe(['w-2']);
    }
}
