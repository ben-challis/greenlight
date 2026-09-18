<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageSuite;

use Greenlight\Attribute\Test;

use Greenlight\Tests\Fixture\CoverageLib\Math;

use function Greenlight\expect;

final readonly class MathTest
{
    #[Test]
    public function addsTwoIntegers(): void
    {
        expect(new Math()->add(2, 3))->toBe(5);
    }
}
