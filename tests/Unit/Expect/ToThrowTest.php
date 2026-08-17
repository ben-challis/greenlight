<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class ToThrowTest
{
    #[Test]
    public function toThrowPassesOnMatchingClass(): void
    {
        Expect::that(static fn() => throw new \DomainException('insufficient funds'))->because('toThrow() passes on matching class')
            ->toThrow(\DomainException::class);
    }

    #[Test]
    public function toThrowPassesOnSubclassesAndMessagePattern(): void
    {
        Expect::that(static fn() => throw new \DomainException('insufficient funds'))->because('toThrow() passes on subclasses and message pattern')
            ->toThrow(\LogicException::class, matching: '/insufficient funds/');
    }

    #[Test]
    public function toThrowPassesOnAnExactMessage(): void
    {
        Expect::that(static fn() => throw new \DomainException('insufficient funds'))->because('toThrow() passes on an exact message')
            ->toThrow(\LogicException::class, message: 'insufficient funds');
    }

    #[Test]
    public function toThrowPassesTheTypedThrowableToACallback(): void
    {
        $previous = new \LengthException('too short');

        Expect::that(static fn() => throw new \DomainException('invalid value', previous: $previous))
            ->toThrow(
                static function (\DomainException $error) use ($previous): void {
                    Expect::that($error->getPrevious())->toBe($previous);
                },
            );
    }

    #[Test]
    public function toThrowRunsTheCallbackOnlyAfterTheThrowableTypeMatches(): void
    {
        $called = false;

        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \RuntimeException('boom'))
                ->toThrow(
                    static function (\DomainException $error) use (&$called): void {
                        $called = true;
                    },
                ),
        );

        Expect::that($called)->toBeFalse();
        Expect::that($detail->message)->toBe(
            "Expected a callable that threw RuntimeException with message 'boom' "
            . 'to throw DomainException and satisfy the throwable callback.',
        );
    }

    #[Test]
    public function toThrowPreservesAThrowableCallbackExpectationFailure(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('boom'))
                ->toThrow(
                    static function (\DomainException $error): void {
                        Expect::that($error->getMessage())->toBe('expected message');
                    },
                ),
        );

        Expect::that($detail->message)->toBe("Expected 'boom' to be 'expected message'.");
        Expect::that($detail->expected)->toBe("'expected message'");
        Expect::that($detail->actual)->toBe("'boom'");
    }

    #[Test]
    public function toThrowFailsWhenNothingIsThrown(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn(): int => 1)->toThrow(\DomainException::class),
        );

        Expect::that($detail->message)->because('toThrow() fails when the callable does not throw')->toBe('Expected a callable that did not throw to throw DomainException.');
        Expect::that($detail->expected)->because('toThrow() fails when the callable does not throw')->toBe('DomainException');
        Expect::that($detail->actual)->because('toThrow() fails when the callable does not throw')->toBe('a callable that did not throw');
    }

    #[Test]
    public function toThrowFailsOnTheWrongClass(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \RuntimeException('boom'))
                ->toThrow(\DomainException::class),
        );

        Expect::that($detail->message)->because('toThrow() fails on the wrong class')->toBe(
            "Expected a callable that threw RuntimeException with message 'boom' to throw DomainException.",
        );
    }

    #[Test]
    public function toThrowFailsOnAMessageMismatch(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('boom'))
                ->toThrow(\DomainException::class, matching: '/insufficient funds/'),
        );

        Expect::that($detail->message)->because('toThrow() fails on a message mismatch')->toBe(
            "Expected a callable that threw DomainException with message 'boom' "
            . 'to throw DomainException with message matching /insufficient funds/.',
        );
    }

    #[Test]
    public function toThrowFailsWhenTheMessageIsNotExactlyEqual(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('insufficient funds now'))
                ->toThrow(\DomainException::class, message: 'insufficient funds'),
        );

        Expect::that($detail->message)->because('toThrow() fails when the message is not exactly equal')->toBe(
            "Expected a callable that threw DomainException with message 'insufficient funds now' "
            . "to throw DomainException with message 'insufficient funds'.",
        );
    }

    #[Test]
    public function notToThrowPassesWhenNothingIsThrown(): void
    {
        Expect::that(static fn(): int => 1)->because('not()->toThrow() passes when the callable does not throw')->not()->toThrow(\DomainException::class);
    }

    #[Test]
    public function notToThrowPassesWhenADifferentThrowableIsThrown(): void
    {
        Expect::that(static fn() => throw new \RuntimeException('boom'))->because('not()->toThrow() passes when a different throwable is thrown')
            ->not()->toThrow(\DomainException::class);
    }

    #[Test]
    public function notToThrowFailsWhenTheThrowableMatches(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('boom'))
                ->not()->toThrow(\DomainException::class),
        );

        Expect::that($detail->message)->because('not()->toThrow() fails when the throwable matches')->toBe(
            "Expected a callable that threw DomainException with message 'boom' not to throw DomainException.",
        );
    }

    #[Test]
    public function notToThrowPassesWhenTheThrowableCallbackExpectationFails(): void
    {
        Expect::that(static fn() => throw new \DomainException('boom'))
            ->not()
            ->toThrow(
                static function (\DomainException $error): void {
                    Expect::that($error->getMessage())->toBe('different');
                },
            );
    }

    #[Test]
    public function notToThrowFailsWhenTheThrowableCallbackPasses(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('boom'))
                ->not()
                ->toThrow(
                    static function (\DomainException $error): void {
                        Expect::that($error->getMessage())->toBe('boom');
                    },
                ),
        );

        Expect::that($detail->message)->toBe(
            "Expected a callable that threw DomainException with message 'boom' "
            . 'not to throw DomainException and satisfy the throwable callback.',
        );
    }

    #[Test]
    public function toThrowGuardsTheSubjectTypeEvenWhenNegated(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->not()->toThrow(\DomainException::class), // @phpstan-ignore greenlight.toThrow.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toThrow() guards the subject type even when negated')
            ->toBe('toThrow() requires a callable subject. The subject type is int.');
    }

    #[Test]
    public function toThrowRejectsInvalidPatternsBeforeInvokingTheSubject(): void
    {
        $invoked = false;

        Expect::that(function () use (&$invoked): void {
            Expect::that(static function () use (&$invoked): void { // @phpstan-ignore greenlight.expectationArgument.pattern (deliberately invalid: tests runtime validation)
                $invoked = true;
            })
                ->toThrow(\DomainException::class, matching: 'not a pattern');
        })->because('toThrow() rejects invalid patterns before invoking the subject')
            ->toThrow(\InvalidArgumentException::class, matching: '/invalid regular expression/');

        Expect::that($invoked)->because('toThrow() rejects invalid patterns before invoking the subject')->toBeFalse();
    }

    #[Test]
    public function toThrowRejectsPatternAndExactMessageBeforeInvokingTheSubject(): void
    {
        $invoked = false;

        $detail = FailureProbe::detailOf(
            static function () use (&$invoked): void {
                $expectation = Expect::that(static function () use (&$invoked): void {
                    $invoked = true;
                });

                $method = new \ReflectionMethod($expectation, 'toThrow');
                $method->invokeArgs($expectation, [
                    'throwable' => \DomainException::class,
                    'matching' => '/insufficient funds/',
                    'message' => 'insufficient funds',
                ]);
            },
        );

        Expect::that($detail->message)->because('toThrow() rejects pattern and exact message before invoking the subject')->toBe(
            'Specify matching: or message: for toThrow(). Do not specify both.',
        );
        Expect::that($invoked)->because('toThrow() rejects pattern and exact message before invoking the subject')->toBeFalse();
    }

    #[Test]
    public function toThrowRejectsAConstraintWithAThrowableCallbackBeforeInvokingTheSubject(): void
    {
        $invoked = false;

        $detail = FailureProbe::detailOf(
            static function () use (&$invoked): void {
                $expectation = Expect::that(static function () use (&$invoked): void {
                    $invoked = true;
                });

                new \ReflectionMethod($expectation, 'toThrow')->invokeArgs(
                    $expectation,
                    [
                        'throwable' => static function (\DomainException $error): void {},
                        'matching' => '/boom/',
                    ],
                );
            },
        );

        Expect::that($detail->message)->toBe(
            'Do not specify matching: or message: when the throwable is a callback.',
        );
        Expect::that($invoked)->toBeFalse();
    }

    /**
     * @param \Closure(): mixed $throwable
     */
    #[Test]
    #[DataSet('invalidThrowableCallbacks')]
    public function toThrowRejectsAnInvalidThrowableCallbackBeforeInvokingTheSubject(
        \Closure $throwable,
        string $message,
    ): void {
        $invoked = false;

        $detail = FailureProbe::detailOf(
            static function () use (&$invoked, $throwable): void {
                $expectation = Expect::that(static function () use (&$invoked): void {
                    $invoked = true;
                });

                new \ReflectionMethod($expectation, 'toThrow')->invokeArgs(
                    $expectation,
                    [$throwable],
                );
            },
        );

        Expect::that($detail->message)->toBe($message);
        Expect::that($invoked)->toBeFalse();
    }

    /**
     * @return iterable<string, array{\Closure, non-empty-string}>
     */
    public static function invalidThrowableCallbacks(): iterable
    {
        yield 'no throwable parameter' => [
            static function (): void {},
            'The throwable callback for toThrow() MUST accept one typed Throwable argument.',
        ];
        yield 'too many required parameters' => [
            static function (\DomainException $error, string $context): void {},
            'The throwable callback for toThrow() MUST accept one typed Throwable argument.',
        ];
        yield 'variadic throwable parameter' => [
            static function (\DomainException ...$error): void {},
            'The throwable callback for toThrow() MUST accept one typed Throwable argument.',
        ];
        yield 'parameter by reference' => [
            static function (\DomainException &$error): void {},
            'The throwable callback for toThrow() MUST accept its argument by value.',
        ];
        yield 'declared return value' => [
            static fn(\DomainException $error): int => 1,
            'The throwable callback for toThrow() MUST return void. Its return type is int.',
        ];
        yield 'missing parameter type' => [
            static function ($error): void {},
            'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type. Its parameter type is missing.',
        ];
        yield 'built-in parameter type' => [
            static function (string $error): void {},
            'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type. Its parameter type is string.',
        ];
        yield 'nullable parameter type' => [
            static function (?\DomainException $error): void {},
            'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type. Its parameter type is ?DomainException.',
        ];
        yield 'union parameter type' => [
            static function (\LengthException|\OutOfBoundsException $error): void {},
            'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type. Its parameter type is LengthException|OutOfBoundsException.',
        ];
        yield 'intersection parameter type' => [
            static function (\Throwable&\Countable $error): void {},
            'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type. Its parameter type is Throwable&Countable.',
        ];
        yield 'non-throwable class parameter type' => [
            static function (\stdClass $error): void {},
            'The throwable callback for toThrow() MUST declare a Throwable parameter type. Its parameter type is stdClass.',
        ];
    }

    #[Test]
    public function toThrowAcceptsScopedThrowableCallbackParameterTypes(): void
    {
        $selfScopedError = new class ('self') extends \DomainException {
            public function throwableCallback(): \Closure
            {
                return static function (self $error): void {
                    Expect::that($error)->toBeInstanceOf(self::class);
                };
            }
        };

        Expect::that(static fn() => throw $selfScopedError)
            ->toThrow($selfScopedError->throwableCallback());

        $parentScopedCallback = new class ('parent') extends \Exception {
            public function throwableCallback(): \Closure
            {
                return static function (parent $error): void {
                    Expect::that($error)->toBeInstanceOf(\Exception::class);
                };
            }
        };

        Expect::that(static fn() => throw new \DomainException('parent'))
            ->toThrow($parentScopedCallback->throwableCallback());
    }

    #[Test]
    public function toThrowRejectsAThrowableCallbackReturnValue(): void
    {
        $detail = FailureProbe::detailOf(
            static function (): void {
                $expectation = Expect::that(static fn() => throw new \DomainException('boom'));

                new \ReflectionMethod($expectation, 'toThrow')->invokeArgs(
                    $expectation,
                    [static fn(\DomainException $error) => 1],
                );
            },
        );

        Expect::that($detail->message)->toBe(
            'The throwable callback for toThrow() MUST return void. It returned int.',
        );
    }
}
