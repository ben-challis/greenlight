<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageSuite;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\CoverageLib\Math;

final readonly class MathTest
{
    #[Test]
    public function addsTwoIntegers(): void
    {
        Expect::that(new Math()->add(2, 3))->toBe(5);
    }
}
