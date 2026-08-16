<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\FunctionAvailable;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelStateResetter;
use Illuminate\Support\Once;

#[SkipUnless(FunctionAvailable::class, 'once')]
final class LaravelOnceStateResetTest
{
    private int $calls = 0;

    #[Test]
    public function resetClearsMemoizedOnceValues(): void
    {
        try {
            Expect::that($this->memoizedValue())
                ->because('Laravel once() MUST memoize a value before the reset')
                ->toBe(1);
            Expect::that($this->memoizedValue())
                ->toBe(1);

            LaravelStateResetter::reset();

            Expect::that($this->memoizedValue())
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
