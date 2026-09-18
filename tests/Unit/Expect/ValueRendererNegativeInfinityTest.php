<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ValueRenderer;

use function Greenlight\expect;

final class ValueRendererNegativeInfinityTest
{
    #[Test]
    public function negativeInfinityKeepsItsSign(): void
    {
        expect(new ValueRenderer()->render(-\INF))
            ->because('negative infinity MUST retain its sign in failure diagnostics')
            ->toBe('-INF');
    }
}
