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
    public function toThrowPassesTheTypedThrowableToAMatchingCallback(): void
    {
        $previous = new \LengthException('too short');

        Expect::that(static fn() => throw new \DomainException('invalid value', previous: $previous))
            ->toThrow(
                \DomainException::class,
                matching: static function (\DomainException $error) use ($previous): void {
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
                    \DomainException::class,
                    matching: static function (\DomainException $error) use (&$called): void {
                        $called = true;
                    },
                ),
        );

        Expect::that($called)->toBeFalse();
        Expect::that($detail->message)->toBe(
            "Expected a callable that threw RuntimeException with message 'boom' "
            . 'to throw DomainException with a throwable that satisfies the matching callback.',
        );
    }

    #[Test]
    public function toThrowPreservesAMatchingCallbackExpectationFailure(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('boom'))
                ->toThrow(
                    \DomainException::class,
                    matching: static function (\DomainException $error): void {
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
    public function notToThrowPassesWhenTheMatchingCallbackExpectationFails(): void
    {
        Expect::that(static fn() => throw new \DomainException('boom'))
            ->not()
            ->toThrow(
                \DomainException::class,
                matching: static function (\DomainException $error): void {
                    Expect::that($error->getMessage())->toBe('different');
                },
            );
    }

    #[Test]
    public function notToThrowFailsWhenTheMatchingCallbackPasses(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(static fn() => throw new \DomainException('boom'))
                ->not()
                ->toThrow(
                    \DomainException::class,
                    matching: static function (\DomainException $error): void {
                        Expect::that($error->getMessage())->toBe('boom');
                    },
                ),
        );

        Expect::that($detail->message)->toBe(
            "Expected a callable that threw DomainException with message 'boom' "
            . 'not to throw DomainException with a throwable that satisfies the matching callback.',
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

    /**
     * @param \Closure(): mixed $matching
     */
    #[Test]
    #[DataSet('invalidMatchingCallbacks')]
    public function toThrowRejectsAnInvalidMatchingCallbackBeforeInvokingTheSubject(
        \Closure $matching,
        string $message,
    ): void {
        $invoked = false;

        $detail = FailureProbe::detailOf(
            static function () use (&$invoked, $matching): void {
                $expectation = Expect::that(static function () use (&$invoked): void {
                    $invoked = true;
                });

                new \ReflectionMethod($expectation, 'toThrow')->invokeArgs(
                    $expectation,
                    [\DomainException::class, $matching],
                );
            },
        );

        Expect::that($detail->message)->toBe($message);
        Expect::that($invoked)->toBeFalse();
    }

    /**
     * @return iterable<string, array{\Closure, non-empty-string}>
     */
    public static function invalidMatchingCallbacks(): iterable
    {
        yield 'no throwable parameter' => [
            static function (): void {},
            'The matching callback for toThrow() must accept one throwable argument.',
        ];
        yield 'too many required parameters' => [
            static function (\DomainException $error, string $context): void {},
            'The matching callback for toThrow() must accept one throwable argument.',
        ];
        yield 'parameter by reference' => [
            static function (\DomainException &$error): void {},
            'The matching callback for toThrow() must accept its throwable argument by value.',
        ];
        yield 'declared return value' => [
            static fn(\DomainException $error): int => 1,
            'The matching callback for toThrow() must return void. Its return type is int.',
        ];
        yield 'incompatible throwable parameter' => [
            static function (\LengthException $error): void {},
            'The matching callback for toThrow() must accept DomainException. Its parameter type is LengthException.',
        ];
    }

    #[Test]
    public function toThrowRejectsAMatchingCallbackReturnValue(): void
    {
        $detail = FailureProbe::detailOf(
            static function (): void {
                $expectation = Expect::that(static fn() => throw new \DomainException('boom'));

                new \ReflectionMethod($expectation, 'toThrow')->invokeArgs(
                    $expectation,
                    [\DomainException::class, static fn(\DomainException $error) => 1],
                );
            },
        );

        Expect::that($detail->message)->toBe(
            'The matching callback for toThrow() must return void. It returned int.',
        );
    }
}
