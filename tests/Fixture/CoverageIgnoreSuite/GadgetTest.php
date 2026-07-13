<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageIgnoreSuite;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\CoverageIgnoreLib\Gadget;

final readonly class GadgetTest
{
    #[Test]
    public function doublesIntegers(): void
    {
        Expect::that(Gadget::double(21))->toBe(42);
    }
}
