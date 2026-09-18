<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;
use Greenlight\Test\ExpectationCounter;

final class FailTest
{
    #[Test]
    public function throwsWithTheGivenReason(): void
    {
        Expect::calling(static fn() => Fail::because('The required value was not found.'))
            ->because('an explicit failure MUST throw with its reason and call site')
            ->toThrow(
                ExpectationFailed::class,
                matching: '/^The required value was not found\. \(at .+:\d+\)$/',
            );
    }

    #[Test]
    public function carriesStructuredFailureDetail(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Fail::because('The result was unusable.'),
        );

        Expect::value($detail->message)->because('carries structured failure detail')->toBe('The result was unusable.');
        Expect::value($detail->expected)->because('carries structured failure detail')->toBeNull();
        Expect::value($detail->actual)->because('carries structured failure detail')->toBeNull();
    }

    #[Test]
    public function anEmptyReasonUsesAClearFallback(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Fail::because(''),
        );

        Expect::value($detail->message)
            ->because('an explicit failure always has a reason')
            ->toBe('The test failed without a reason.');
    }

    #[Test]
    public function aZeroStringReasonRemainsTheFailureMessage(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Fail::because('0'),
        );

        Expect::value($detail->message)
            ->because('an explicit failure MUST preserve a zero-string reason')
            ->toBe('0');
    }

    #[Test]
    public function failureLocationPointsAtTheCallSite(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => Fail::because('Stopped here.'));

        Expect::value($detail->location?->file)->because('failure location points at the call site')->toBe(__FILE__);
        Expect::value($detail->location?->line)->because('failure location points at the call site')->toBe($line);
    }

    #[Test]
    public function countsAsAnExpectation(): void
    {
        ExpectationCounter::reset();

        try {
            Fail::because('Count this failure.');
        } catch (ExpectationFailed) {
        }

        $count = ExpectationCounter::count();

        Expect::value($count)->because('counts as an expectation')->toBe(1);
    }
}
