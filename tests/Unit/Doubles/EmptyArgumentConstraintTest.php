<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class EmptyArgumentConstraintTest
{
    #[Test]
    public function anExplicitEmptyConstraintRejectsSuppliedOptionalArguments(): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('withDefaults')
                ->with()
                ->once()
                ->andReturns('default');
        });

        Expect::that(static fn(): string => $wide->withDefaults(11))
            ->because('an explicit empty argument constraint MUST reject supplied optional arguments')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
    }
}
