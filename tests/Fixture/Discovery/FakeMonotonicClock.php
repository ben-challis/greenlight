<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Discovery;

use Greenlight\Doubles\Fake;

final class FakeMonotonicClock implements Fake
{
    /**
     * @var list<int>
     */
    private array $readings;

    public function __construct(int ...$readings)
    {
        $this->readings = \array_values($readings);
    }

    public function __invoke(): int
    {
        return \array_shift($this->readings)
            ?? throw new \LogicException('No monotonic clock readings remain.');
    }
}
