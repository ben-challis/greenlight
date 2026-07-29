<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Runner\Orchestrator\Orchestrator;
use Greenlight\Tests\Fixture\LeakSuite\CleanTest;
use Greenlight\Tests\Fixture\Runner\Orchestrator\RecycleBeforeIsolatedWorker;
use Greenlight\Tests\Support\CollectingEventSink;

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
        $orchestrator = new Orchestrator(
            workerCommand: [\PHP_BINARY, '-r', $bootstrap],
            workingDirectory: $root,
        );
        $sink = new CollectingEventSink();
        $id = new TestId(CleanTest::class, 'passesAndIsCollectable');
        $plan = new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method, isolated: true)),
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
            ->toEqual(new ResultSummary(passed: 1))
            ->and($workers)
            ->toBe(['w-2']);
    }
}
