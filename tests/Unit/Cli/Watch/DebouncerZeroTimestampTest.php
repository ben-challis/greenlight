<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\Debouncer;

use function Greenlight\expect;

final class DebouncerZeroTimestampTest
{
    #[Test]
    public function aZeroTimestampStartsTheQuietPeriod(): void
    {
        $debouncer = new Debouncer(0.5);
        $debouncer->noteChange(0.0);

        expect($debouncer->shouldFire(0.5))
            ->because('a zero timestamp MUST start the quiet period')
            ->toBeTrue();
    }
}
