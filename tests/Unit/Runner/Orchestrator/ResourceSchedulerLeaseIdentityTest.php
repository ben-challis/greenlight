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

final readonly class ResourceSchedulerLeaseIdentityTest
{
    #[Test]
    public function aLeaseIdDoesNotAuthorizeADifferentLeaseObject(): void
    {
        $first = $this->unit('Acme\\FirstTest');
        $second = $this->unit('Acme\\SecondTest');
        $scheduler = new ResourceScheduler([$first, $second], [], []);
        $lease = $this->assigned($scheduler);
        $forged = new ResourceLease($lease->id, $lease->unit);

        Expect::that(static fn() => $scheduler->release($forged))
            ->because('only the exact lease object MUST release its resource slots')
            ->toThrow(
                \LogicException::class,
                message: 'Resource lease 1 is unknown or has already been released.',
            );

        Expect::that(static fn() => $scheduler->release($lease))
            ->because('rejecting a forged lease MUST preserve the real lease')
            ->not()
            ->toThrow(\Throwable::class);

        Expect::that($this->assigned($scheduler)->unit)
            ->because('the real lease MUST release its resource slot')
            ->toBe($second);
    }

    /**
     * @param non-empty-string $class
     */
    private function unit(string $class): SchedulingUnit
    {
        $id = new TestId($class, 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($class, 'runs', resources: ['database'])),
        ]), isolated: false);
    }

    private function assigned(ResourceScheduler $scheduler): ResourceLease
    {
        $decision = $scheduler->dispatch(freshWorker: true);

        if (!$decision->lease instanceof ResourceLease) {
            Fail::because(\sprintf('Expected an assignment, got %s.', $decision->kind->name));
        }

        return $decision->lease;
    }
}
