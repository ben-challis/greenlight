<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Clock;
use Greenlight\Tests\Fixture\Doubles\Stubbable;

final class StubTest
{
    #[Test]
    public function unconfiguredMethodsReturnDerivedDefaults(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class);

        new Expect()->that($stub->name())->toBe('')
            ->and($stub->count())->toBe(0)
            ->and($stub->ratio())->toBe(0.0)
            ->and($stub->flag())->toBeFalse()
            ->and($stub->items())->toBe([])
            ->and($stub->maybeId())->toBeNull()
            ->and($stub->clock())->toBeInstanceOf(Clock::class)
            ->and($stub->itself())->toBe($stub);

        $stub->touch();

        $doubles->dispose();
    }

    #[Test]
    public function configuredCallsAnswerFromThePlan(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Stubbable::class, static function (MockPlan $plan): void {
            $plan->expects('name')->andReturns('configured');
        });

        new Expect()->that($stub->name())->toBe('configured');

        $doubles->dispose();
    }

    #[Test]
    public function nothingIsEnforcedOnAStub(): void
    {
        $doubles = new Doubles();
        $doubles->stub(Stubbable::class, static function (MockPlan $plan): void {
            $plan->expects('name')->times(5)->andReturns('never called');
        });

        $doubles->dispose();
    }

    #[Test]
    public function configuredAnswersSelectByArguments(): void
    {
        $doubles = new Doubles();
        $stub = $doubles->stub(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, 1)->andReturns(2);
            $plan->expects('add')->with(2, 2)->andReturns(4);
        });

        new Expect()->that($stub->add(1, 1))->toBe(2)
            ->and($stub->add(2, 2))->toBe(4)
            ->and($stub->add(5, 5))->toBe(0);

        $doubles->dispose();
    }
}
