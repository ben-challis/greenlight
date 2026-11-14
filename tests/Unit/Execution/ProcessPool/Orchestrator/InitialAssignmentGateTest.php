<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Orchestrator\InitialAssignmentGate;
use Greenlight\Execution\ProcessPool\Orchestrator\InitialWorkerAssignment;

use function Greenlight\expect;

final readonly class InitialAssignmentGateTest
{
    #[Test]
    public function onlyTheFirstSpawnsHoldReturningWorkers(): void
    {
        $gate = new InitialAssignmentGate(2, InitialWorkerAssignment::Progressive);
        $gate->spawned('w-1');
        $gate->spawned('w-2');

        expect($gate->barrierPassed())
            ->because('progressive assignment MUST start with an open barrier')
            ->toBeTrue();
        expect($gate->holdsReturningWorkers())->toBeTrue();
        expect($gate->release('w-1'))->toBeTrue();
        expect($gate->release('w-1'))
            ->because('a second release of the same worker MUST NOT retry waiting workers')
            ->toBeFalse();
        expect($gate->release('w-2'))->toBeTrue();

        $gate->spawned('w-3');

        expect($gate->holdsReturningWorkers())
            ->because('a replacement worker MUST NOT close the gate again')
            ->toBeFalse();
    }

    #[Test]
    public function theBarrierOpensWhenTheInitialWorkersAreReady(): void
    {
        $gate = new InitialAssignmentGate(2, InitialWorkerAssignment::AfterAllReady);

        expect($gate->barrierPassed())->toBeFalse();
        expect($gate->openBarrier(1))->toBeFalse();
        expect($gate->openBarrier(2))->toBeTrue();
        expect($gate->barrierPassed())->toBeTrue();
    }
}
