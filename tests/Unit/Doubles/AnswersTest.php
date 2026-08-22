<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Wide;

final readonly class AnswersTest
{
    private const string CONFLICTING_ANSWER = 'The expectation on add() already has an answer. '
        . 'Configure exactly one of andReturns(), andReturnsSequence(), andReturnsUsing(), or andThrows().';

    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function aSequenceReturnsItsValuesInOrder(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->times(3)->andReturnsSequence(1, 2, 3);
        });

        Expect::that($calculator->add(0, 0))->because('a sequence returns its values in order')->toBe(1);
        Expect::that($calculator->add(0, 0))->toBe(2);
        Expect::that($calculator->add(0, 0))->toBe(3);
    }

    #[Test]
    public function aSequenceCanReturnNull(): void
    {
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('nullable')
                ->times(2)
                ->andReturnsSequence(null, 'ready');
        });

        Expect::that($wide->nullable('first'))
            ->because('a null sequence element MUST be consumed as a configured return value')
            ->toBeNull();
        Expect::that($wide->nullable('second'))->toBe('ready');
    }

    #[Test]
    public function aMatchedCallAfterSequenceExhaustionIsAnAuthoringError(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->atLeast(1)->andReturnsSequence(5);
        });

        Expect::that($calculator->add(0, 0))
            ->because('a matched call after sequence exhaustion is an authoring error')
            ->toBe(5);
        Expect::that(static fn(): int => $calculator->add(0, 0))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'The return sequence for add() has no value after 1 time. '
                    . 'Add values or use a stricter call count.',
            );
    }

    #[Test]
    public function anEmptySequenceIsRejected(): void
    {
        Expect::that(fn(): mixed => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturnsSequence(); // @phpstan-ignore greenlight.mockPlan.answer (deliberately invalid: tests runtime validation)
        }))
            ->because('an empty sequence is rejected')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'andReturnsSequence() on add() requires at least one value.',
            );
    }

    #[Test]
    public function andReturnsUsingReceivesTheCallArguments(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturnsUsing(static fn(int $a, int $b): int => $a + $b);
        });

        Expect::that($calculator->add(19, 23))->because('andReturnsUsing() receives the call arguments')->toBe(42);
    }

    #[Test]
    public function aSecondAnswerKindOnOneExpectationIsRejected(): void
    {
        Expect::that(fn(): mixed => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(1)->andReturnsSequence(2, 3);
        }))
            ->because('a second answer kind on one expectation is rejected')
            ->toThrow(InvalidDoubleUsage::class, message: self::CONFLICTING_ANSWER);
    }

    #[Test]
    public function aCallbackAfterAReturnValueIsRejected(): void
    {
        Expect::that(fn(): mixed => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(1)->andReturnsUsing(static fn(): int => 2);
        }))
            ->because('a callback after a return value is rejected')
            ->toThrow(InvalidDoubleUsage::class, message: self::CONFLICTING_ANSWER);
    }

    #[Test]
    public function aReturnValueAfterASequenceIsRejected(): void
    {
        Expect::that(fn(): mixed => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturnsSequence(1)->andReturns(2);
        }))
            ->because('a return value after a sequence is rejected')
            ->toThrow(InvalidDoubleUsage::class, message: self::CONFLICTING_ANSWER);
    }

    #[Test]
    public function aThrowableAfterAReturnValueIsRejected(): void
    {
        Expect::that(fn(): mixed => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andReturns(1)->andThrows(new \RuntimeException('boom'));
        }))
            ->because('a throwable after a return value is rejected')
            ->toThrow(InvalidDoubleUsage::class, message: self::CONFLICTING_ANSWER);
    }

    #[Test]
    public function aReturnValueAfterAThrowableIsRejected(): void
    {
        Expect::that(fn(): mixed => $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->andThrows(new \RuntimeException('boom'))->andReturns(1);
        }))
            ->because('a return value after a throwable is rejected')
            ->toThrow(InvalidDoubleUsage::class, message: self::CONFLICTING_ANSWER);
    }

    #[Test]
    public function aSequenceWithTimesStaysConsistent(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->times(2)->andReturnsSequence(10, 20);
        });

        Expect::that($calculator->add(1, 1))->because('a sequence with times stays consistent')->toBe(10);
        Expect::that($calculator->add(2, 2))->toBe(20);
    }
}
