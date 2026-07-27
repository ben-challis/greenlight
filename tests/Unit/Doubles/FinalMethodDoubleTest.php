<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\PlanningBoundaries;

final class FinalMethodDoubleTest
{
    #[Test]
    public function aClassDoubleKeepsItsOriginalFinalMethods(): void
    {
        $doubles = new Doubles();

        try {
            $double = $doubles->stub(PlanningBoundaries::class);

            Expect::that(static function () use ($double): void {
                $double->finalMethod();
            })
                ->because('a class double MUST keep its original final methods')
                ->not()
                ->toThrow(\Throwable::class);
        } finally {
            $doubles->dispose();
        }
    }
}
