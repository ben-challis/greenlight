<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\ArgumentCaptor;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Recorder;

final class ArgumentMatchingTest
{
    #[Test]
    public function typeMatchesBuiltinValues(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(Argument::type('int'), Argument::type('int'))->once()->andReturns(5);
        });

        Expect::that($calculator->add(2, 3))->toBe(5);

        $doubles->dispose();
    }

    #[Test]
    public function typeMatchesInterfaceInstances(): void
    {
        $doubles = new Doubles();
        $recorder = $doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->with(Argument::type(\DateTimeInterface::class))->once();
        });

        $recorder->record(new \DateTimeImmutable('2026-01-01'));

        $doubles->dispose();
    }

    #[Test]
    public function typeMismatchFailsWithTheMatcherDescription(): void
    {
        $doubles = new Doubles();
        $recorder = $doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->with(Argument::type('int'))->once();
        });

        try {
            $recorder->record('not an int');
        } catch (ExpectationFailed $failure) {
            $detail = $failure->detail();

            Expect::that($detail->expected)->toContain('type(int)')
                ->and($detail->actual)->toBe("record('not an int')");

            return;
        }

        throw new \RuntimeException('The mismatching call did not fail.');
    }

    #[Test]
    public function predicateMatchesWhenTheClosureReturnsTrue(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')
                ->with(Argument::predicate(static fn(mixed $value): bool => \is_int($value) && $value > 0, 'positive'), 1)
                ->once()
                ->andReturns(3);
        });

        Expect::that($calculator->add(2, 1))->toBe(3);

        $doubles->dispose();
    }

    #[Test]
    public function predicateMismatchRendersItsDescription(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')
                ->with(Argument::predicate(static fn(mixed $value): bool => \is_int($value) && $value > 0, 'positive'), 1)
                ->once()
                ->andReturns(3);
        });

        try {
            $calculator->add(-2, 1);
        } catch (ExpectationFailed $failure) {
            Expect::that($failure->detail()->expected)->toContain('predicate(positive)');

            return;
        }

        throw new \RuntimeException('The mismatching call did not fail.');
    }

    #[Test]
    public function equalsUsesDeepEquality(): void
    {
        $doubles = new Doubles();
        $recorder = $doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->with(Argument::equals(['a' => [1, 2]]))->once();
        });

        $recorder->record(['a' => [1, 2]]);

        $doubles->dispose();
    }

    #[Test]
    public function bareValuesAndMatchersMixInOneWith(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, Argument::type('int'))->once()->andReturns(9);
        });

        Expect::that($calculator->add(1, 8))->toBe(9);

        $doubles->dispose();
    }

    #[Test]
    public function aCaptorInWithCollectsValuesInCallOrder(): void
    {
        $doubles = new Doubles();
        $captor = Argument::captor();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan) use ($captor): void {
            $plan->expects('add')->with($captor, 7)->times(2)->andReturns(0);
        });

        $calculator->add(1, 7);
        $calculator->add(999, 7);

        Expect::that($captor->values())->toEqual([1, 999])
            ->and($captor->value())->toBe(999);

        $doubles->dispose();
    }

    #[Test]
    public function aCaptorWithoutCapturesRefusesToProduceAValue(): void
    {
        Expect::that(static fn(): mixed => Argument::captor()->value())
            ->toThrow(DoublesError::class, '/captured/');
    }

    #[Test]
    public function captureArgumentRecordsEveryMatchedCall(): void
    {
        $doubles = new Doubles();
        $captor = null;
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan) use (&$captor): void {
            $captor = $plan->expects('add')->times(2)->andReturns(0)->captureArgument(1);
        });

        $calculator->add(1, 9);
        $calculator->add(2, 8);

        if (!$captor instanceof ArgumentCaptor) {
            throw new \RuntimeException('captureArgument() did not hand back a captor.');
        }

        Expect::that($captor->values())->toEqual([9, 8]);

        $doubles->dispose();
    }

    #[Test]
    public function captureArgumentWorksAlongsideWithConstraints(): void
    {
        $doubles = new Doubles();
        $captor = null;
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan) use (&$captor): void {
            $captor = $plan->expects('add')->with(Argument::any(), 7)->once()->andReturns(0)->captureArgument(0);
        });

        $calculator->add(42, 7);

        if (!$captor instanceof ArgumentCaptor) {
            throw new \RuntimeException('captureArgument() did not hand back a captor.');
        }

        Expect::that($captor->value())->toBe(42);

        $doubles->dispose();
    }

    #[Test]
    public function captureArgumentRejectsNegativePositions(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->captureArgument(-1);
        }))->toThrow(DoublesError::class, '/-1/');
    }

    #[Test]
    public function captorsOnlySeeCallsTheirOwnExpectationMatched(): void
    {
        $doubles = new Doubles();
        $first = Argument::captor();
        $second = Argument::captor();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan) use ($first, $second): void {
            $plan->expects('add')->with(1, $first)->once()->andReturns(0);
            $plan->expects('add')->with(2, $second)->once()->andReturns(0);
        });

        $calculator->add(1, 10);
        $calculator->add(2, 20);

        Expect::that($first->values())->toEqual([10])
            ->and($second->values())->toEqual([20]);

        $doubles->dispose();
    }
}
