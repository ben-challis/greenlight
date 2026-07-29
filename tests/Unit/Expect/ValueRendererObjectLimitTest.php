<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Tests\Fixture\Expect\WideObject;

final readonly class ValueRendererObjectLimitTest
{
    #[Test]
    public function objectRenderingStopsAfterTenProperties(): void
    {
        Expect::that(new ValueRenderer()->render(new WideObject()))
            ->because('object diagnostics MUST stay within the property limit')
            ->toBe(
                WideObject::class
                . ' {one: 1, two: 2, three: 3, four: 4, five: 5, six: 6, '
                . 'seven: 7, eight: 8, nine: 9, ten: 10, ...}',
            );
    }
}
