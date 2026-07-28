<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\ExpectationCounter;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Expect\Fail;

final class FailTest
{
    #[Test]
    public function throwsWithTheGivenReason(): void
    {
        Expect::that(static fn() => Fail::because('The required value was not found.'))
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

        Expect::that($detail->message)->because('carries structured failure detail')->toBe('The result was unusable.');
        Expect::that($detail->expected)->because('carries structured failure detail')->toBeNull();
        Expect::that($detail->actual)->because('carries structured failure detail')->toBeNull();
    }

    #[Test]
    public function anEmptyReasonUsesAClearFallback(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Fail::because(''),
        );

        Expect::that($detail->message)
            ->because('an explicit failure always has a reason')
            ->toBe('The test failed without a reason.');
    }

    #[Test]
    public function failureLocationPointsAtTheCallSite(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => Fail::because('Stopped here.'));

        Expect::that($detail->location?->file)->because('failure location points at the call site')->toBe(__FILE__);
        Expect::that($detail->location?->line)->because('failure location points at the call site')->toBe($line);
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

        Expect::that($count)->because('counts as an expectation')->toBe(1);
    }
}
