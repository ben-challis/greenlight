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
        try {
            Fail::because('The required value was not found.');
        } catch (ExpectationFailed $failure) {
            Expect::that($failure->getMessage())
                ->toStartWith('The required value was not found. (at ');
        }
    }

    #[Test]
    public function carriesStructuredFailureDetail(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Fail::because('The result was unusable.'),
        );

        Expect::that($detail->message)->toBe('The result was unusable.');
        Expect::that($detail->expected)->toBeNull();
        Expect::that($detail->actual)->toBeNull();
    }

    #[Test]
    public function failureLocationPointsAtTheCallSite(): void
    {
        $line = __LINE__ + 1;
        $detail = FailureProbe::detailOf(static fn() => Fail::because('Stopped here.'));

        Expect::that($detail->location?->file)->toBe(__FILE__);
        Expect::that($detail->location?->line)->toBe($line);
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

        Expect::that($count)->toBe(1);
    }
}
