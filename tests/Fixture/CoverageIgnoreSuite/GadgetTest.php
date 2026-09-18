<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CoverageIgnoreSuite;

use Greenlight\Attribute\Test;

use Greenlight\Tests\Fixture\CoverageIgnoreLib\Gadget;

use function Greenlight\expect;

final readonly class GadgetTest
{
    #[Test]
    public function doublesIntegers(): void
    {
        expect(Gadget::double(21))->toBe(42);
    }
}
