<?php

declare(strict_types=1);

namespace MutationPrototype\Tests;

use Greenlight\Attribute\Test;

use Greenlight\Expect\ExpectationFailed;
use MutationPrototype\Temperature;

use function Greenlight\expect;

final class TemperatureTest
{
    /** @throws ExpectationFailed */
    #[Test]
    public function zeroIsFreezing(): void
    {
        expect(new Temperature()->isFreezing(0.0))->toBeTrue();
    }

    /** @throws ExpectationFailed */
    #[Test]
    public function warmIsNotFreezing(): void
    {
        expect(new Temperature()->isFreezing(5.0))->toBeFalse();
    }
}
