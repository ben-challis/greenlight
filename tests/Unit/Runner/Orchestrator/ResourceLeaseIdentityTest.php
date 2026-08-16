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
use Greenlight\Runner\Orchestrator\DispatchKind;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\ResourceScheduler;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final readonly class ResourceLeaseIdentityTest
{
    #[Test]
    public function concurrentLeasesHaveDistinctIdentityAndReleaseIndependently(): void
    {
        $thirdUnit = $this->unit('Acme\\ThirdTest');
        $fourthUnit = $this->unit('Acme\\FourthTest');
        $scheduler = new ResourceScheduler([
            $this->unit('Acme\\FirstTest'),
            $this->unit('Acme\\SecondTest'),
            $thirdUnit,
            $fourthUnit,
        ], [], ['database' => 2]);

        $first = $this->assigned($scheduler);
        $second = $this->assigned($scheduler);

        Expect::that($first->id)
            ->because('the first resource lease MUST use the first lease ID')
            ->toBe(1);
        Expect::that($second->id)
            ->because('the second resource lease MUST use a distinct lease ID')
            ->toBe(2);

        Expect::that($scheduler->dispatch(true)->kind)
            ->because('two concurrent resource leases MUST use all configured capacity')
            ->toBe(DispatchKind::Wait);

        $scheduler->release($first);

        Expect::that($this->assigned($scheduler)->unit)
            ->because('the first release MUST restore one resource slot')
            ->toBe($thirdUnit);
        Expect::that($scheduler->dispatch(true)->kind)
            ->because('the first release MUST restore only one resource slot')
            ->toBe(DispatchKind::Wait);

        $scheduler->release($second);

        Expect::that($this->assigned($scheduler)->unit)
            ->because('the second release MUST independently restore one resource slot')
            ->toBe($fourthUnit);
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
