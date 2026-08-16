<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Wide;

final readonly class EmptyArgumentConstraintTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function anExplicitEmptyConstraintRejectsSuppliedOptionalArguments(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('withDefaults')
                ->withNoArguments()
                ->once()
                ->andReturns('default');
        });

        Expect::that(static fn(): string => $wide->withDefaults(11))
            ->because('an explicit empty argument constraint MUST reject supplied optional arguments')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');

        Expect::that(static fn() => $doubles->dispose())
            ->because('verification MUST report the unexpected call')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
    }

    #[Test]
    public function anExplicitEmptyConstraintAcceptsNoArguments(): void
    {
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('withDefaults')
                ->withNoArguments()
                ->once()
                ->andReturns('default');
        });

        Expect::that($wide->withDefaults())->toBe('default');
    }
}
