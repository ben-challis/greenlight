<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Expect\MatcherEvaluation;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Expect\FakeClock;

use function Greenlight\expect;

final readonly class BecauseTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function becauseAddsTheReasonToTheFailureMessage(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(false)->because('the retry flag must stay enabled')->toBeTrue(),
        );

        expect($detail->message)->because('because() adds the reason to the failure message')->toBe('Expected false to be true because the retry flag must stay enabled.');
        expect($detail->expected)->because('because() adds the reason to the failure message')->toBe('true');
        expect($detail->actual)->because('because() adds the reason to the failure message')->toBe('false');
    }

    #[Test]
    public function becausePreservesAZeroStringReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(false)->because('0')->toBeTrue(),
        );

        expect($detail->message)
            ->because('because() MUST preserve a zero-string reason')
            ->toBe('Expected false to be true because 0.');
    }

    #[Test]
    public function becauseDoesNotChangeAPassingMatcher(): void
    {
        expect(true)->because('a passing matcher retains the reason without reporting it')->toBeTrue();
    }

    #[Test]
    public function becauseAppliesToSubsequentMatchers(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(1)->because('the id must be valid')->not()->toBe(2)->toBe(1)->toBe(2),
        );

        expect($detail->message)->toBe('Expected 1 to be 2 because the id must be valid.');
    }

    #[Test]
    public function anotherBecauseReplacesTheReasonForSubsequentMatchers(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(1)
                ->because('the id must be valid')->toBe(1)
                ->because('  the id must change  ')->toBeInt()->toBe(2),
        );

        expect($detail->message)->toBe('Expected 1 to be 2 because the id must change.');
    }

    #[Test]
    public function separateExpectationsDoNotInheritTheReason(): void
    {
        expect(1)->because('the id must be valid')->toBe(1);

        $detail = FailureProbe::detailOf(static fn() => expect(1)->toBe(2));

        expect($detail->message)->toBe('Expected 1 to be 2.');
    }

    #[Test]
    public function becauseCombinesWithNegationInAnyOrder(): void
    {
        $expected = 'Expected 1 not to be 1 because the id must change.';

        $notFirst = FailureProbe::detailOf(
            static fn() => expect(1)->not()->because('the id must change')->toBe(1),
        );
        expect($notFirst->message)->because('because() combines with negation in both orders')->toBe($expected);

        $becauseFirst = FailureProbe::detailOf(
            static fn() => expect(1)->because('the id must change')->not()->toBe(1),
        );
        expect($becauseFirst->message)->because('because() combines with negation in both orders')->toBe($expected);
    }

    #[Test]
    public function becauseTrimsTheReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(false)->because('  the flag must stay enabled  ')->toBeTrue(),
        );

        expect($detail->message)->because('because() trims the reason')->toBe('Expected false to be true because the flag must stay enabled.');
    }

    #[Test]
    public function anEmptyReasonIsAUsageFailure(): void
    {
        $empty = FailureProbe::detailOf(static function (): void {
            expect(true)->because(''); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        });

        expect($empty->message)->because('an empty reason is a usage failure')->toBe('because() requires a non-empty reason.');

        $blank = FailureProbe::detailOf(
            static fn() => expect(true)->because('   '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
        );

        expect($blank->message)->because('an empty reason is a usage failure')->toBe('because() requires a non-empty reason.');
    }

    #[Test]
    public function anInvalidReasonPreservesThePendingValueNegationAndReason(): void
    {
        $expectation = expect(1)->not()->because('the id must change');
        $invalid = FailureProbe::detailOf(
            static fn() => $expectation->because(' '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
        );

        expect($invalid->actual)->toBe('null');

        $next = FailureProbe::detailOf(static fn() => $expectation->toBe(1));

        expect($next->message)->toBe('Expected 1 not to be 1 because the id must change.');
        $expectation->toBe(1);
    }

    #[Test]
    public function anInvalidReasonPreservesThePendingCallNegation(): void
    {
        $expectation = expect()->calling(static fn(): int => 1)->not();
        $invalid = FailureProbe::detailOf(
            static fn() => $expectation->because(' '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
        );

        expect($invalid->actual)->toBe('null');
        $expectation->toThrow();
    }

    #[Test]
    public function anInvalidReasonPreservesThePendingEventuallyNegation(): void
    {
        ExpectationRuntime::withClock(new FakeClock(), static function (): void {
            $pending = expect()->calling(static fn(): int => 1)->returnValue()->eventually()->not();
            $invalid = FailureProbe::detailOf(
                static fn() => $pending->because(' '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
            );

            expect($invalid->actual)->toBe('null');
            $pending->within(0.01)->toBe(2);
        });
    }

    #[Test]
    public function anInvalidReasonPreservesThePendingConsistentlyNegation(): void
    {
        ExpectationRuntime::withClock(new FakeClock(), static function (): void {
            $pending = expect()->calling(static fn(): int => 1)->returnValue()->consistently()->not();
            $invalid = FailureProbe::detailOf(
                static fn() => $pending->because(' '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
            );

            expect($invalid->actual)->toBe('null');
            $pending->for(0.01)->toBe(2);
        });
    }

    #[Test]
    public function anInvalidReasonPreservesTheTemporalNegation(): void
    {
        ExpectationRuntime::withClock(new FakeClock(), static function (): void {
            $expectation = expect()->calling(static fn(): int => 1)->returnValue()->eventually()->within(0.01)->not();
            $invalid = FailureProbe::detailOf(
                static fn() => $expectation->because(' '), // @phpstan-ignore greenlight.expectationArgument.reason (deliberately invalid: tests runtime validation)
            );

            expect($invalid->actual)->toBeNull();
            $expectation->toBe(2);
        });
    }

    #[Test]
    public function anInvalidReasonClearsTheNegationOnAMatcherEvaluation(): void
    {
        $evaluation = new MatcherEvaluation(1, new ValueRenderer())->not();
        $invalid = FailureProbe::detailOf(
            static fn() => $evaluation->because(' '),
        );

        expect($invalid->actual)->toBe('1');
        $evaluation->toBe(1);
    }

    #[Test]
    public function aUsageFailureIgnoresThePendingReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(42)->because('the reason applies to matcher failures only')->toContain('x'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        expect($detail->message)->because('a usage failure ignores the pending reason')
            ->toBe('toContain() requires a string or iterable subject. The subject type is int.');
    }

    #[Test]
    public function extensionMatchersCarryTheReason(): void
    {
        $restoreExtensions = Expect::install([
            new class implements ExpectationExtension {
                /** @return array{toBeOdd: \Closure(mixed): bool} */
                #[\Override]
                public function matchers(): array
                {
                    return [
                        'toBeOdd' => static fn(mixed $subject): bool => \is_int($subject) && $subject % 2 === 1,
                    ];
                }
            },
        ]);
        $this->cleanup->defer($restoreExtensions);

        $detail = FailureProbe::detailOf(
            static fn() => expect(2)->because('the id must be odd')->toBeInt()->__call('toBeOdd', []),
        );

        expect($detail->message)->because('extension matchers carry the reason')
            ->toBe('Expected 2 to satisfy the extension matcher toBeOdd because the id must be odd.');
    }
}
