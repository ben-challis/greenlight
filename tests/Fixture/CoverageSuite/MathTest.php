<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageSuite;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\CoverageLib\Math;

final readonly class MathTest
{
    public function __construct(
        private Expect $expect,
    ) {}

    #[Test]
    public function addsTwoIntegers(): void
    {
        $this->expect->that(new Math()->add(2, 3))->toBe(5);
    }
}
