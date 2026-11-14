<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\Attempt;

use Greenlight\Test\ExpectationCounter;

final class PassingProbe
{
    public function passes(): void
    {
        ExpectationCounter::increment();
    }
}
