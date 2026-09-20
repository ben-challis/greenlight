<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchDecision;
use Greenlight\Execution\ProcessPool\Orchestrator\DispatchKind;
use Greenlight\Execution\ProcessPool\Orchestrator\ResourceLease;
use Greenlight\Tests\Support\SchedulingFixture;

use function Greenlight\expect;

final readonly class DispatchDecisionTest
{
    #[Test]
    public function assignmentCarriesItsExactLease(): void
    {
        $lease = new ResourceLease(41, SchedulingFixture::unit('Acme\\ExampleTest'));
        $decision = DispatchDecision::assign($lease);

        expect($decision->kind)
            ->because('an assignment MUST use the assign decision kind')
            ->toBe(DispatchKind::Assign);
        expect($decision->lease)
            ->because('an assignment MUST carry the exact resource lease')
            ->toBe($lease);
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

        expect($decision->kind)
            ->because('a non-assignment decision MUST use its requested decision kind')
            ->toBe($kind);
        expect($decision->lease)
            ->because('a non-assignment decision MUST NOT carry a resource lease')
            ->toBeNull();
    }

    /**
     * @return iterable<string, array{non-empty-string, DispatchKind}>
     */
    public static function emptyDecisions(): iterable
    {
        yield 'wait' => ['wait', DispatchKind::Wait];
        yield 'drain' => ['drain', DispatchKind::Drain];
    }

}
