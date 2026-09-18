<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Laravel\LaravelStateResetter;
use Illuminate\Support\Once;

use function Greenlight\expect;

#[SkipUnless(FunctionAvailable::class, 'once')]
final class LaravelOnceStateResetTest
{
    private int $calls = 0;

    #[Test]
    public function resetClearsMemoizedOnceValues(): void
    {
        try {
            expect($this->memoizedValue())
                ->because('Laravel once() MUST memoize a value before the reset')
                ->toBe(1);
            expect($this->memoizedValue())
                ->toBe(1);

            LaravelStateResetter::reset();

            expect($this->memoizedValue())
                ->because('a reset MUST discard Laravel once() values from the previous application')
                ->toBe(2);
        } finally {
            Once::flush();
        }
    }

    private function memoizedValue(): int
    {
        return \once(fn(): int => ++$this->calls);
    }
}
