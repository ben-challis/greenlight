<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final class AnswersTest
{
    #[Test]
    public function aSequenceReturnsItsValuesInOrder(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->times(3)->andReturnsSequence(1, 2, 3);
        });

        Expect::that($calculator->add(0, 0))->toBe(1)
            ->and($calculator->add(0, 0))->toBe(2)
            ->and($calculator->add(0, 0))->toBe(3);

        $doubles->dispose();
    }

    #[Test]
    public function aMatchedCallAfterSequenceExhaustionIsAnAuthoringError(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->atLeast(1)->andReturnsSequence(5);
        });

        Expect::that($calculator->add(0, 0))->toBe(5)
            ->and(static fn(): int => $calculator->add(0, 0))
            ->toThrow(DoublesError::class, '/add/');
    }

    #[Test]
    public function anEmptySequenceIsRejected(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturnsSequence();
        }))->toThrow(DoublesError::class, '/at least one value/');
    }

    #[Test]
    public function andReturnsUsingReceivesTheCallArguments(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturnsUsing(static fn(int $a, int $b): int => $a + $b);
        });

        Expect::that($calculator->add(19, 23))->toBe(42);

        $doubles->dispose();
    }

    #[Test]
    public function aSecondAnswerKindOnOneExpectationIsRejected(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(1)->andReturnsSequence(2, 3);
        }))->toThrow(DoublesError::class, '/add/');
    }

    #[Test]
    public function aCallbackAfterAReturnValueIsRejected(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(1)->andReturnsUsing(static fn(): int => 2);
        }))->toThrow(DoublesError::class, '/add/');
    }

    #[Test]
    public function aReturnValueAfterASequenceIsRejected(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturnsSequence(1)->andReturns(2);
        }))->toThrow(DoublesError::class, '/add/');
    }

    #[Test]
    public function aThrowableAfterAReturnValueIsRejected(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(1)->andThrows(new \RuntimeException('boom'));
        }))->toThrow(DoublesError::class, '/add/');
    }

    #[Test]
    public function aReturnValueAfterAThrowableIsRejected(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andThrows(new \RuntimeException('boom'))->andReturns(1);
        }))->toThrow(DoublesError::class, '/add/');
    }

    #[Test]
    public function aSequenceWithTimesStaysConsistent(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->times(2)->andReturnsSequence(10, 20);
        });

        Expect::that($calculator->add(1, 1))->toBe(10)
            ->and($calculator->add(2, 2))->toBe(20);

        $doubles->dispose();
    }
}
