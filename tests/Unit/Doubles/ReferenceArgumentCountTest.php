<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Wide;

final readonly class ReferenceArgumentCountTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function referenceMethodsRejectSurplusArguments(): void
    {
        $spy = $this->doubles->spy(Wide::class);
        $items = [];

        Expect::that(static function () use ($spy, &$items): void {
            $spy->byReference($items, 'extra'); // @phpstan-ignore arguments.count (Deliberately passes too many arguments.)
        })->toThrow(InvalidDoubleUsage::class, '/supplies 2 arguments, but the method accepts at most 1 argument/');
    }
}
