<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Laravel\LaravelStateResetter;
use Illuminate\Support\Lottery;

use function Greenlight\expect;

#[SkipUnless(ClassAvailable::class, Lottery::class)]
final class LaravelLotteryStateResetTest
{
    #[Test]
    public function resetClearsForcedLotteryResults(): void
    {
        Lottery::alwaysWin();

        try {
            expect(Lottery::odds(0, 1)->choose())
                ->because('a forced Laravel lottery MUST win before the reset')
                ->toBeTrue();

            LaravelStateResetter::reset();

            expect(Lottery::odds(0, 1)->choose())
                ->because('a reset MUST restore normal Laravel lottery results')
                ->toBeFalse();
        } finally {
            Lottery::determineResultsNormally();
        }
    }
}
