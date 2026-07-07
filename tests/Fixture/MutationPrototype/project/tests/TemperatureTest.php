<?php

declare(strict_types=1);

namespace MutationPrototype\Tests;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use MutationPrototype\Temperature;

final class TemperatureTest
{
    public function __construct(private readonly Expect $expect) {}

    #[Test]
    public function zeroIsFreezing(): void
    {
        $this->expect->that(new Temperature()->isFreezing(0.0))->toBeTrue();
    }

    #[Test]
    public function warmIsNotFreezing(): void
    {
        $this->expect->that(new Temperature()->isFreezing(5.0))->toBeFalse();
    }
}
