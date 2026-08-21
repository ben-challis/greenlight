<?php

declare(strict_types=1);

namespace MutationPrototype\Tests;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use MutationPrototype\Temperature;

final class TemperatureTest
{
    /** @throws ExpectationFailed */
    #[Test]
    public function zeroIsFreezing(): void
    {
        Expect::that(new Temperature()->isFreezing(0.0))->toBeTrue();
    }

    /** @throws ExpectationFailed */
    #[Test]
    public function warmIsNotFreezing(): void
    {
        Expect::that(new Temperature()->isFreezing(5.0))->toBeFalse();
    }
}
