<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ValueRenderer;

final class ValueRendererNegativeInfinityTest
{
    #[Test]
    public function negativeInfinityKeepsItsSign(): void
    {
        Expect::that(new ValueRenderer()->render(-\INF))
            ->because('negative infinity MUST retain its sign in failure diagnostics')
            ->toBe('-INF');
    }
}
