<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\CacheAlpha;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Stubbable;

final class LeakTest
{
    #[Test]
    public function everyDoubleIsCollectableAfterDisposeAndUnset(): void
    {
        $doubles = new Doubles();

        $mock = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturns(3);
        });
        $stub = $doubles->stub(Stubbable::class);
        $spy = $doubles->spy(CacheAlpha::class);

        $mock->add(1, 2);

        $references = [
            \WeakReference::create($mock),
            \WeakReference::create($stub),
            \WeakReference::create($spy),
        ];

        $doubles->dispose();
        unset($mock, $stub, $spy);
        \gc_collect_cycles();

        $survivors = \array_values(\array_filter(
            $references,
            static fn(\WeakReference $reference): bool => $reference->get() !== null,
        ));

        new Expect()->that($survivors)->toBe([]);
    }
}
