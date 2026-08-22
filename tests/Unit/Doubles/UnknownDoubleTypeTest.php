<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;

final class UnknownDoubleTypeTest
{
    #[Test]
    public function unknownTypesGiveExactGuidance(): void
    {
        $doubles = new Doubles();
        $mock = new \ReflectionMethod(Doubles::class, 'mock');

        Expect::that(static fn(): mixed => $mock->invoke($doubles, 'Example\MissingContract'))
            ->because('a double needs a loadable class or interface')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles cannot load Example\MissingContract as a class or interface.',
            );
    }
}
