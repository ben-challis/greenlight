<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;

final class BecauseTest
{
    #[Test]
    public function becauseAddsTheReasonToTheFailureMessage(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(false)->because('the retry flag must stay enabled')->toBeTrue(),
        );

        Expect::that($detail->message)->because('because() adds the reason to the failure message')->toBe('Expected false to be true because the retry flag must stay enabled.');
        Expect::that($detail->expected)->because('because() adds the reason to the failure message')->toBe('true');
        Expect::that($detail->actual)->because('because() adds the reason to the failure message')->toBe('false');
    }

    #[Test]
    public function becausePreservesAZeroStringReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(false)->because('0')->toBeTrue(),
        );

        Expect::that($detail->message)
            ->because('because() MUST preserve a zero-string reason')
            ->toBe('Expected false to be true because 0.');
    }

    #[Test]
    public function becauseDoesNotChangeAPassingMatcher(): void
    {
        Expect::that(true)->because('a passing matcher consumes the reason without reporting it')->toBeTrue();
    }

    #[Test]
    public function becauseIsConsumedByTheNextMatcher(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(1)->because('the first matcher consumes this reason')->toBe(1)->toBe(2),
        );

        Expect::that($detail->message)->because('because() is consumed by the next matcher')->toBe('Expected 1 to be 2.');
    }

    #[Test]
    public function becauseCombinesWithNegationInAnyOrder(): void
    {
        $expected = 'Expected 1 not to be 1 because the id must change.';

        $notFirst = FailureProbe::detailOf(
            static fn() => Expect::that(1)->not()->because('the id must change')->toBe(1),
        );
        Expect::that($notFirst->message)->because('because() combines with negation in both orders')->toBe($expected);

        $becauseFirst = FailureProbe::detailOf(
            static fn() => Expect::that(1)->because('the id must change')->not()->toBe(1),
        );
        Expect::that($becauseFirst->message)->because('because() combines with negation in both orders')->toBe($expected);
    }

    #[Test]
    public function becauseTrimsTheReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(false)->because('  the flag must stay enabled  ')->toBeTrue(),
        );

        Expect::that($detail->message)->because('because() trims the reason')->toBe('Expected false to be true because the flag must stay enabled.');
    }

    #[Test]
    public function anEmptyReasonIsAUsageFailure(): void
    {
        $empty = FailureProbe::detailOf(static function (): void {
            Expect::that(true)->because(''); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        });

        Expect::that($empty->message)->because('an empty reason is a usage failure')->toBe('because() requires a non-empty reason.');

        $blank = FailureProbe::detailOf(static fn() => Expect::that(true)->because('   '));

        Expect::that($blank->message)->because('an empty reason is a usage failure')->toBe('because() requires a non-empty reason.');
    }

    #[Test]
    public function aUsageFailureIgnoresThePendingReason(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->because('the reason applies to matcher failures only')->toContain('x'), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('a usage failure ignores the pending reason')
            ->toBe('toContain() requires a string or iterable subject. The subject type is int.');
    }

    #[Test]
    public function extensionMatchersCarryTheReason(): void
    {
        Expect::install([
            new class implements ExpectationExtension {
                #[\Override]
                public function matchers(): array
                {
                    return [
                        'toBeOdd' => static fn(mixed $subject): bool => \is_int($subject) && $subject % 2 === 1,
                    ];
                }
            },
        ]);

        try {
            $detail = FailureProbe::detailOf(
                static fn() => Expect::that(2)->because('the id must be odd')->__call('toBeOdd', []),
            );
        } finally {
            Expect::install([]);
        }

        Expect::that($detail->message)->because('extension matchers carry the reason')
            ->toBe('Expected 2 to satisfy the extension matcher toBeOdd because the id must be odd.');
    }
}
