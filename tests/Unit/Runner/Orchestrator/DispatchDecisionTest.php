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
use Greenlight\Runner\Orchestrator\DispatchDecision;
use Greenlight\Runner\Orchestrator\DispatchKind;
use Greenlight\Runner\Orchestrator\ResourceLease;
use Greenlight\Runner\Orchestrator\SchedulingUnit;

final readonly class DispatchDecisionTest
{
    #[Test]
    public function assignmentCarriesItsExactLease(): void
    {
        $lease = new ResourceLease(41, $this->unit());
        $decision = DispatchDecision::assign($lease);

        Expect::that([
            'kind' => $decision->kind,
            'lease' => $decision->lease,
        ])
            ->because('an assignment MUST carry the exact resource lease')
            ->toBe([
                'kind' => DispatchKind::Assign,
                'lease' => $lease,
            ]);
    }

    #[Test]
    #[DataSet('emptyDecisions')]
    public function nonAssignmentDecisionsDoNotCarryALease(string $factory, DispatchKind $kind): void
    {
        $decision = match ($factory) {
            'wait' => DispatchDecision::wait(),
            'drain' => DispatchDecision::drain(),
            default => throw new \LogicException(\sprintf('Unknown decision factory "%s".', $factory)),
        };

        Expect::that([
            'kind' => $decision->kind,
            'lease' => $decision->lease,
        ])
            ->because('non-assignment decisions MUST identify their action without a lease')
            ->toBe([
                'kind' => $kind,
                'lease' => null,
            ]);
    }

    /**
     * @return iterable<string, array{non-empty-string, DispatchKind}>
     */
    public static function emptyDecisions(): iterable
    {
        yield 'wait' => ['wait', DispatchKind::Wait];
        yield 'drain' => ['drain', DispatchKind::Drain];
    }

    private function unit(): SchedulingUnit
    {
        $id = new TestId('Acme\\ExampleTest', 'runs');

        return new SchedulingUnit(new ExecutionPlan([
            new PlanEntry($id, new TestMetadata($id->class, $id->method)),
        ]), isolated: false);
    }
}
