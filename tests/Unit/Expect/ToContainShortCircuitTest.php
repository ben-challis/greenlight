<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final readonly class ToContainShortCircuitTest
{
    #[Test]
    public function iterableStopsAfterTheMatchingValue(): void
    {
        $values = static function (): \Generator {
            yield 'target';

            throw new \LogicException('The matcher consumed the iterable after it found the target.');
        };

        expect($values())
            ->because('toContain() MUST stop consuming an iterable after it finds the target')
            ->toContain('target');
    }
}
