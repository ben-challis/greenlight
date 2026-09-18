<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Laravel\LaravelStateResetter;
use Illuminate\Support\Str;

use function Greenlight\expect;

#[SkipUnless(ClassAvailable::class, Str::class)]
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

            expect($factoryCalls)
                ->because('Laravel reset MUST remove a custom random-string factory')
                ->toBe(0);
        } finally {
            Str::createRandomStringsNormally();
        }
    }
}
