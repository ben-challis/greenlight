<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

use Greenlight\Doubles\Fake;
use Greenlight\Expect\PollingClock;

final class FakePollingClock implements PollingClock, Fake
{
    public float $time = 0.0;

    /**
     * @var list<float>
     */
    public array $sleeps = [];

    #[\Override]
    public function now(): float
    {
        return $this->time;
    }

    #[\Override]
    public function sleep(float $seconds): void
    {
        $this->sleeps[] = $seconds;
        $this->time += $seconds;
    }
}
