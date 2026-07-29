<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final readonly class ResourceSchedulerRequeueOrderTest
{
    #[Test]
    #[DataSet('queueKinds')]
    public function requeuedWorkAppendsBehindPendingWork(bool $isolated, bool $freshWorker): void
    {
        $pending = $this->unit('Acme\\PendingTest', $isolated);
        $requeued = $this->unit('Acme\\RetriedTest', $isolated);
        $scheduler = new ResourceScheduler(
            $isolated ? [] : [$pending],
            $isolated ? [$pending] : [],
            [],
        );
        $scheduler->requeue($requeued);

        $first = $this->assigned($scheduler, $freshWorker);
        $scheduler->release($first);
        $second = $this->assigned($scheduler, $freshWorker);

        Expect::that([
            $first->unit->plan->classes(),
            $second->unit->plan->classes(),
        ])
            ->because('requeued work MUST append behind pending work in its queue')
            ->toBe([
                ['Acme\\PendingTest'],
                ['Acme\\RetriedTest'],
            ]);
    }

    /**
     * @return iterable<string, array{bool, bool}>
     */
    public static function queueKinds(): iterable
    {
        yield 'pooled queue' => [false, false];
        yield 'isolated queue' => [true, true];
    }

    /**
     * @param non-empty-string $class
     */
    private function unit(string $class, bool $isolated): SchedulingUnit
    {
        $id = new TestId($class, 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($class, 'runs', resources: ['database'])),
        ]), $isolated);
    }

    private function assigned(ResourceScheduler $scheduler, bool $freshWorker): ResourceLease
    {
        $decision = $scheduler->dispatch($freshWorker);

        if (!$decision->lease instanceof ResourceLease) {
            Fail::because(\sprintf('Expected an assignment, got %s.', $decision->kind->name));
        }

        return $decision->lease;
    }
}
