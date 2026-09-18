<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Tests\Fixture\Doubles\PlanningBoundaries;

use function Greenlight\expect;

final readonly class FinalMethodDoubleTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function aClassDoubleKeepsItsOriginalFinalMethods(): void
    {
        $double = $this->doubles->stub(PlanningBoundaries::class);

        expect()->calling(static function () use ($double): void {
            $double->finalMethod();
        })
            ->because('a class double MUST keep its original final methods')
            ->not()
            ->toThrow(\Throwable::class);
    }
}
