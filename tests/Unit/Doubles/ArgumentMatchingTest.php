<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\ArgumentCaptor;
use Greenlight\Doubles\ArgumentMatcher;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Recorder;
use Greenlight\Tests\Fixture\Doubles\Wide;

final readonly class ArgumentMatchingTest
{
    public function __construct(private Doubles $doubles) {}

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
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(Argument::type('int'), Argument::type('int'))->once()->andReturns(5);
        });

        Expect::that($calculator->add(2, 3))->because('type matches builtin values')->toBe(5);
    }

    #[Test]
    public function typeMatchesInterfaceInstances(): void
    {
        $recorder = $this->doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->with(Argument::type(\DateTimeInterface::class))->once();
        });

        $recorder->record(new \DateTimeImmutable('2026-01-01'));
    }

    #[Test]
    public function typeMismatchFailsWithTheMatcherDescription(): void
    {
        $doubles = new Doubles();
        $recorder = $doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->with(Argument::type('int'))->once();
        });

        Expect::that(static function () use ($recorder): void {
            $recorder->record('not an int');
        })
            ->because("record('not an int') MUST fail its type(int) argument matcher")
            ->toThrow(static function (ExpectationFailed $failure): void {
                $detail = $failure->detail();

                Expect::that($detail->expected)->toContain('type(int)');
                Expect::that($detail->actual)->toBe("record('not an int')");
            });
    }

    #[Test]
    #[DataSet('invalidArgumentTypes')]
    public function typeMatchersRejectMissingTypeNames(string $type): void
    {
        Expect::that(static fn(): ArgumentMatcher => Argument::type($type))
            ->because('argument type matchers MUST identify a type')
            ->toThrow(
                InvalidDoubleUsage::class,
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
    public function typeIntersectionsRequireEveryType(): void
    {
        $matcher = Argument::intersection(FirstArgumentType::class, SecondArgumentType::class);

        Expect::that($matcher->matches(new CombinedArgumentType()))
            ->because('intersection() accepts a value that has every specified type')
            ->toBeTrue();
        Expect::that($matcher->matches(new FirstArgumentTypeOnly()))->toBeFalse();
        Expect::that(Argument::intersection('int', 'int')->matches(42))->toBeTrue();
        Expect::that(Argument::intersection('int', 'string')->matches(42))->toBeFalse();
    }

    #[Test]
    public function typeUnionsRequireOneType(): void
    {
        $matcher = Argument::union(FirstArgumentType::class, SecondArgumentType::class);

        Expect::that($matcher->matches(new CombinedArgumentType()))
            ->because('union() accepts a value that has one or more specified types')
            ->toBeTrue();
        Expect::that($matcher->matches(new FirstArgumentTypeOnly()))->toBeTrue();
        Expect::that($matcher->matches(new \stdClass()))->toBeFalse();
        Expect::that(Argument::union('int', 'string')->matches('value'))->toBeTrue();
    }

    #[Test]
    public function typeCombinationDiagnosticsPreserveTypeOrder(): void
    {
        Expect::that(Argument::intersection(FirstArgumentType::class, SecondArgumentType::class)->describe())
            ->toBe('intersection(Greenlight\\Tests\\Unit\\Doubles\\FirstArgumentType, '
                . 'Greenlight\\Tests\\Unit\\Doubles\\SecondArgumentType)');
        Expect::that(Argument::union('int', 'string', \DateTimeInterface::class)->describe())
            ->toBe('union(int, string, DateTimeInterface)');
    }

    #[Test]
    public function typeCombinationsRejectMissingTypeNames(): void
    {
        Expect::that(static fn(): ArgumentMatcher => Argument::intersection('int', ''))
            ->because('type combination matchers MUST identify every type')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Argument::intersection() requires type names that contain a non-space character.',
            );
        Expect::that(static fn(): ArgumentMatcher => Argument::union('   ', 'string'))
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Argument::union() requires type names that contain a non-space character.',
            );
    }

    #[Test]
    public function predicateMatchesWhenTheClosureReturnsTrue(): void
    {
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')
                ->with(Argument::predicate(static fn(mixed $value): bool => \is_int($value) && $value > 0, 'positive'), 1)
                ->once()
                ->andReturns(3);
        });

        Expect::that($calculator->add(2, 1))->because('predicate matches when the closure returns true')->toBe(3);
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

        Expect::that(static fn(): int => $calculator->add(-2, 1))
            ->because('add(-2, 1) MUST fail its predicate(positive) argument matcher')
            ->toThrow(static function (ExpectationFailed $failure): void {
                Expect::that($failure->detail()->expected)->toContain('predicate(positive)');
            });
    }

    #[Test]
    public function equalsUsesDeepEquality(): void
    {
        $expected = ['a' => (object) ['values' => [1, 2]]];
        $actual = ['a' => (object) ['values' => [1, 2]]];
        $recorder = $this->doubles->mock(Recorder::class, static function (MockPlan $plan) use ($expected): void {
            $plan->expects('record')->with(Argument::equals($expected))->once();
        });

        $recorder->record($actual);
    }

    #[Test]
    public function bareObjectValuesMatchByIdentity(): void
    {
        $expected = (object) ['values' => [1, 2]];
        $recorder = $this->doubles->mock(Recorder::class, static function (MockPlan $plan) use ($expected): void {
            $plan->expects('record')->with($expected)->once();
        });

        $recorder->record($expected);
    }

    #[Test]
    public function bareObjectValuesRejectEqualCopies(): void
    {
        $expected = (object) ['values' => [1, 2]];
        $actual = (object) ['values' => [1, 2]];
        $doubles = new Doubles();
        $recorder = $doubles->mock(Recorder::class, static function (MockPlan $plan) use ($expected): void {
            $plan->expects('record')->with($expected)->once();
        });

        Expect::that(static function () use ($recorder, $actual): void {
            $recorder->record($actual);
        })
            ->because('a bare object value in with() MUST match by identity')
            ->toThrow(ExpectationFailed::class, '/unexpected call/');
    }

    #[Test]
    public function allOfMatchesWhenEveryMatcherMatches(): void
    {
        $recorder = $this->doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->with(Argument::allOf(
                Argument::type(\DateTimeInterface::class),
                Argument::predicate(
                    static fn(\DateTimeInterface $value): bool => $value->getTimestamp() > 0,
                    'positive timestamp',
                ),
            ))->once();
        });

        $recorder->record(new \DateTimeImmutable('2026-01-01'));
    }

    #[Test]
    public function allOfStopsAfterTheFirstMatcherRejectsTheValue(): void
    {
        $predicateCalled = false;
        $matcher = Argument::allOf(
            Argument::type(\DateTimeInterface::class),
            Argument::predicate(static function (\DateTimeInterface $value) use (&$predicateCalled): bool {
                $predicateCalled = true;

                return $value->getTimestamp() > 0;
            }),
        );

        Expect::that($matcher->matches('not a date'))
            ->because('allOf() MUST stop after the type matcher rejects the value')
            ->toBeFalse();
        Expect::that($predicateCalled)->toBeFalse();
    }

    #[Test]
    public function allOfDiagnosticsDescribeEachMatcherInOrder(): void
    {
        $matcher = Argument::allOf(
            Argument::type(\DateTimeInterface::class),
            Argument::predicate(static fn(mixed $value): bool => $value !== null, 'not null'),
        );

        Expect::that($matcher->describe())
            ->because('allOf() diagnostics MUST preserve matcher order')
            ->toBe('allOf(type(DateTimeInterface), predicate(not null))');
    }

    #[Test]
    public function allOfRejectsCaptors(): void
    {
        Expect::that(static fn(): ArgumentMatcher => Argument::allOf(Argument::any(), Argument::captor()))
            ->because('a captor in allOf() cannot record the selected call')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Argument::allOf() does not accept a captor. Put the captor directly in with().',
            );
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
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->with(1, Argument::type('int'))->once()->andReturns(9);
        });

        Expect::that($calculator->add(1, 8))->because('bare values and matchers mix in one with')->toBe(9);
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
        $captor = Argument::captor();
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan) use ($captor): void {
            $plan->expects('add')->with($captor, 7)->times(2)->andReturns(0);
        });

        $calculator->add(1, 7);
        $calculator->add(999, 7);

        Expect::that($captor->values())->because('a captor in with collects values in call order')->toEqual([1, 999]);
        Expect::that($captor->value())->toBe(999);
    }

    #[Test]
    public function aCaptorWithoutCapturesRefusesToProduceAValue(): void
    {
        Expect::that(static fn(): mixed => Argument::captor()->value())
            ->because('a captor without captures refuses to produce a value')
            ->toThrow(InvalidDoubleUsage::class, message: 'The captor has no value. No matched call supplied a value.');
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
        $captor = null;
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan) use (&$captor): void {
            $captor = $plan->expects('add')->times(2)->andReturns(0)->captureArgument(1);
        });

        $calculator->add(1, 9);
        $calculator->add(2, 8);

        Expect::that($captor)
            ->because('captureArgument() MUST return ArgumentCaptor.')
            ->toBeInstanceOf(ArgumentCaptor::class);

        Expect::that($captor->values())->because('capture argument records every matched call')->toEqual([9, 8]);
    }

    #[Test]
    public function captureArgumentWorksAlongsideWithConstraints(): void
    {
        $captor = null;
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan) use (&$captor): void {
            $captor = $plan->expects('add')->with(Argument::any(), 7)->once()->andReturns(0)->captureArgument(0);
        });

        $calculator->add(42, 7);

        Expect::that($captor)
            ->because('captureArgument() MUST return ArgumentCaptor.')
            ->toBeInstanceOf(ArgumentCaptor::class);

        Expect::that($captor->value())->because('capture argument works alongside with constraints')->toBe(42);
    }

    #[Test]
    public function captureArgumentRejectsNegativePositions(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): mixed => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->captureArgument(-1); // @phpstan-ignore greenlight.mockPlan.capturePosition (deliberately invalid: tests runtime validation)
        }))->because('capture argument rejects negative positions')
            ->toThrow(InvalidDoubleUsage::class, message: 'captureArgument(-1) requires a position of zero or more.');
    }

    #[Test]
    public function captorsOnlySeeCallsTheirOwnExpectationMatched(): void
    {
        $first = Argument::captor();
        $second = Argument::captor();
        $calculator = $this->doubles->mock(Calculator::class, static function (MockPlan $plan) use ($first, $second): void {
            $plan->expects('add')->with(1, $first)->once()->andReturns(0);
            $plan->expects('add')->with(2, $second)->once()->andReturns(0);
        });

        $calculator->add(1, 10);
        $calculator->add(2, 20);

        Expect::that($first->values())->because('captors only see calls their own expectation matched')->toEqual([10]);
        Expect::that($second->values())->toEqual([20]);
    }
}

interface FirstArgumentType {}

interface SecondArgumentType {}

final class CombinedArgumentType implements FirstArgumentType, SecondArgumentType {}

final class FirstArgumentTypeOnly implements FirstArgumentType {}
