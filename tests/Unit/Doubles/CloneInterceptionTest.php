<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\CloneProbe;
use Greenlight\Tests\Fixture\Doubles\FinalCloneProbe;

final readonly class CloneInterceptionTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function mocksInterceptCloneAndRegisterTheClone(): void
    {
        CloneProbe::$calls = 0;
        $double = $this->doubles->mock(CloneProbe::class, static function (MockPlan $plan): void {
            $plan->expects('__clone')->once();
        });

        $clone = clone $double;

        Expect::that(CloneProbe::$calls)
            ->because('a mock MUST NOT run the doubled clone method')
            ->toBe(0);
        Expect::that($this->doubles->callsTo($clone, '__clone'))
            ->because('the clone MUST use the same interaction state as the original double')
            ->toEqual([[]]);
    }

    #[Test]
    public function spiesRecordClone(): void
    {
        $double = $this->doubles->spy(CloneProbe::class);
        $clone = clone $double;

        Expect::that($this->doubles->callsTo($clone, '__clone'))
            ->because('a spy MUST record the clone interaction')
            ->toEqual([[]]);
    }

    #[Test]
    public function stubsRejectClone(): void
    {
        $double = $this->doubles->stub(CloneProbe::class);

        Expect::that(static fn(): object => clone $double)
            ->because('a stub MUST reject the clone interaction')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Code called "__clone()" on the stub of "' . CloneProbe::class . '". '
                    . 'Stubs only satisfy a type. Use mock() with explicit expectations for interactions.',
            );
    }

    #[Test]
    public function finalCloneMethodsKeepTheirImplementation(): void
    {
        FinalCloneProbe::$calls = 0;
        $double = $this->doubles->stub(FinalCloneProbe::class);

        $clone = clone $double;

        Expect::that(FinalCloneProbe::$calls)
            ->because('a class double cannot intercept a final clone method')
            ->toBe(1);
        Expect::that($clone)->toBeInstanceOf(FinalCloneProbe::class);
    }
}
