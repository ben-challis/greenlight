<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelStateResetter;

final class LaravelOnceStateResetTest
{
    private int $calls = 0;

    #[Test]
    public function resetClearsMemoizedOnceValues(): void
    {
        Expect::that($this->memoizedValue())
            ->because('Laravel once() MUST memoize a value before the reset')
            ->toBe(1)
            ->and($this->memoizedValue())
            ->toBe(1);

        LaravelStateResetter::reset();

        Expect::that($this->memoizedValue())
            ->because('a reset MUST discard Laravel once() values from the previous application')
            ->toBe(2);
    }

    private function memoizedValue(): int
    {
        return \once(fn(): int => ++$this->calls);
    }
}
