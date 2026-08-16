<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\ArgumentCaptor;
use Greenlight\Doubles\ArgumentMatcher;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Recorder;
use Greenlight\Tests\Fixture\Doubles\Wide;

final class ArgumentMatchingTest
{
    /**
     * @param list<int> $expectedRest
     * @param list<int> $actualRest
     */
    #[Test]
    #[DataSet('differentArgumentCounts')]
    public function exactArgumentMatchingRequiresTheSameArgumentCount(array $expectedRest, array $actualRest): void
    {
        $doubles = new Doubles();
        $wide = $doubles->mock(Wide::class, static function (MockPlan $plan) use ($expectedRest): void {
            $plan->expects('variadic')
                ->with('head', ...$expectedRest)
                ->once()
                ->andReturns([]);
        });

        Expect::that(static fn(): array => $wide->variadic('head', ...$actualRest))
            ->because('exact argument matching requires the same argument count')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
    }

    /**
     * @return iterable<string, array{list<int>, list<int>}>
     */
    public static function differentArgumentCounts(): iterable
    {
        yield 'missing argument' => [[1, 2], [1]];
        yield 'additional argument' => [[1], [1, 2]];
    }

    #[Test]
    public function typeMatchesBuiltinValues(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(Argument::type('int'), Argument::type('int'))->once()->andReturns(5);
        });

        Expect::that($calculator->add(2, 3))->because('type matches builtin values')->toBe(5);

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

            Expect::that($detail->expected)->toContain('type(int)');
            Expect::that($detail->actual)->toBe("record('not an int')");

            return;
        }

        Fail::because("Expected record('not an int') to fail its type(int) argument matcher.");
    }

    #[Test]
    #[DataSet('invalidArgumentTypes')]
    public function typeMatchersRejectMissingTypeNames(string $type): void
    {
        Expect::that(static fn(): ArgumentMatcher => Argument::type($type))
            ->because('argument type matchers MUST identify a type')
            ->toThrow(
                DoublesError::class,
                message: 'Argument::type() requires a type name that contains a non-space character.',
            );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidArgumentTypes(): iterable
    {
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
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

        Expect::that($calculator->add(2, 1))->because('predicate matches when the closure returns true')->toBe(3);

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

        Fail::because('Expected add(-2, 1) to fail its predicate(positive) argument matcher.');
    }

    #[Test]
    public function equalsUsesDeepEquality(): void
    {
        $expected = ['a' => (object) ['values' => [1, 2]]];
        $actual = ['a' => (object) ['values' => [1, 2]]];
        $doubles = new Doubles();
        $recorder = $doubles->mock(Recorder::class, static function (MockPlan $plan) use ($expected): void {
            $plan->expects('record')->with(Argument::equals($expected))->once();
        });

        $recorder->record($actual);

        $doubles->dispose();
    }

    #[Test]
    public function equalsDiagnosticsRenderTheExpectedValue(): void
    {
        Expect::that(Argument::equals(['a' => [1, 2]])->describe())
            ->because('equals() diagnostics render the expected value')
            ->toBe("equals(['a' => [1, 2]])");
    }

    #[Test]
    public function bareValuesAndMatchersMixInOneWith(): void
    {
        $doubles = new Doubles();
        $calculator = $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, Argument::type('int'))->once()->andReturns(9);
        });

        Expect::that($calculator->add(1, 8))->because('bare values and matchers mix in one with')->toBe(9);

        $doubles->dispose();
    }

    #[Test]
    public function anyDiagnosticsNameTheConstraint(): void
    {
        Expect::that(Argument::any()->describe())
            ->because('any() diagnostics identify the argument constraint')
            ->toBe('any()');
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

        Expect::that($captor->values())->because('a captor in with collects values in call order')->toEqual([1, 999]);
        Expect::that($captor->value())->toBe(999);

        $doubles->dispose();
    }

    #[Test]
    public function aCaptorWithoutCapturesRefusesToProduceAValue(): void
    {
        Expect::that(static fn(): mixed => Argument::captor()->value())
            ->because('a captor without captures refuses to produce a value')
            ->toThrow(DoublesError::class, message: 'The captor has no value. No matched call supplied a value.');
    }

    #[Test]
    public function captorDiagnosticsNameTheConstraint(): void
    {
        Expect::that(Argument::captor()->describe())
            ->because('captor diagnostics identify the argument constraint')
            ->toBe('captor()');
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
            Fail::because(\sprintf(
                'Expected captureArgument() to return ArgumentCaptor, got %s.',
                \get_debug_type($captor),
            ));
        }

        Expect::that($captor->values())->because('capture argument records every matched call')->toEqual([9, 8]);

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
            Fail::because(\sprintf(
                'Expected captureArgument() to return ArgumentCaptor, got %s.',
                \get_debug_type($captor),
            ));
        }

        Expect::that($captor->value())->because('capture argument works alongside with constraints')->toBe(42);

        $doubles->dispose();
    }

    #[Test]
    public function captureArgumentRejectsNegativePositions(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->captureArgument(-1);
        }))->because('capture argument rejects negative positions')
            ->toThrow(DoublesError::class, message: 'captureArgument(-1) requires a position of zero or more.');
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

        Expect::that($first->values())->because('captors only see calls their own expectation matched')->toEqual([10]);
        Expect::that($second->values())->toEqual([20]);

        $doubles->dispose();
    }
}
