<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelStateResetter;
use Illuminate\Support\Str;

final class LaravelStateResetterTest
{
    #[Test]
    public function customRandomStringFactoriesDoNotSurviveReset(): void
    {
        $factoryCalls = 0;
        Str::createRandomStringsUsing(static function (int $length) use (&$factoryCalls): string {
            ++$factoryCalls;

            return \str_repeat('x', $length);
        });

        try {
            LaravelStateResetter::reset();
            Str::random(8);

            Expect::that($factoryCalls)
                ->because('Laravel reset MUST remove a custom random-string factory')
                ->toBe(0);
        } finally {
            Str::createRandomStringsNormally();
        }
    }
}
