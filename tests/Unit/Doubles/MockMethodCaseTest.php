<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final readonly class MockMethodCaseTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function expectationMethodNamesFollowPhpCaseInsensitivity(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('ADD')->with(1, 2)->once()->andReturns(3);
        });

        Expect::that($calculator->add(1, 2))
            ->because('mock method names MUST follow PHP case-insensitive dispatch')
            ->toBe(3);
    }
}
