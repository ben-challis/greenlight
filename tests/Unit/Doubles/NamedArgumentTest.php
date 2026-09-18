<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Tests\Fixture\Doubles\Calculator;

use function Greenlight\expect;

final readonly class NamedArgumentTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function namedArgumentsFollowTheDeclaredParameterOrder(): void
    {
        /** @var list<int>|null $received */
        $received = null;
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan) use (&$received): void {
            $plan->expects('add')
                ->with(1, 2)
                ->once()
                ->andReturnsUsing(static function (int $a, int $b) use (&$received): int {
                    $received = [$a, $b];

                    return $a + $b;
                });
        });

        $arguments = ['b' => 2, 'a' => 1];

        expect($calculator->add(...$arguments))
            ->because('named double arguments follow their declared parameter order')
            ->toBe(3);
        expect($received)->toBe([1, 2]);
    }
}
