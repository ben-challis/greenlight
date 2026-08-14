<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner\Orchestrator;

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

final readonly class ResourceLeaseIdentityTest
{
    #[Test]
    public function concurrentLeasesHaveDistinctIdentityAndReleaseIndependently(): void
    {
        $scheduler = new ResourceScheduler([
            $this->unit('Acme\\FirstTest'),
            $this->unit('Acme\\SecondTest'),
        ], [], ['database' => 2]);

        $first = $this->assigned($scheduler);
        $second = $this->assigned($scheduler);

        Expect::that($first->id)
            ->because('the first resource lease MUST use the first lease ID')
            ->toBe(1);
        Expect::that($second->id)
            ->because('the second resource lease MUST use a distinct lease ID')
            ->toBe(2);

        Expect::that(static function () use ($scheduler, $first, $second): void {
            $scheduler->release($first);
            $scheduler->release($second);
        })
            ->because('each concurrent resource lease MUST release independently')
            ->not()
            ->toThrow(\Throwable::class);
    }

    /**
     * @param non-empty-string $class
     */
    private function unit(string $class): SchedulingUnit
    {
        $id = new TestId($class, 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($class, 'runs', resources: ['database'])),
        ]), false);
    }

    private function assigned(ResourceScheduler $scheduler): ResourceLease
    {
        $decision = $scheduler->dispatch(true);

        if (!$decision->lease instanceof ResourceLease) {
            Fail::because(\sprintf('Expected an assignment, got %s.', $decision->kind->name));
        }

        return $decision->lease;
    }
}
