<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelStateResetter;
use Illuminate\Support\Lottery;

final class LaravelLotteryStateResetTest
{
    #[Test]
    public function resetClearsForcedLotteryResults(): void
    {
        Lottery::alwaysWin();

        try {
            Expect::that(Lottery::odds(0, 1)->choose())
                ->because('a forced Laravel lottery MUST win before the reset')
                ->toBeTrue();

            LaravelStateResetter::reset();

            Expect::that(Lottery::odds(0, 1)->choose())
                ->because('a reset MUST restore normal Laravel lottery results')
                ->toBeFalse();
        } finally {
            Lottery::determineResultsNormally();
        }
    }
}
