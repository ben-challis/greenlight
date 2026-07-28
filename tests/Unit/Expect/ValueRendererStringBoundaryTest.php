<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ValueRenderer;

final readonly class ValueRendererStringBoundaryTest
{
    #[Test]
    public function aStringAtTheCharacterLimitRemainsComplete(): void
    {
        $value = \str_repeat('x', 120);

        Expect::that(new ValueRenderer()->render($value))
            ->because('a diagnostic string at the limit MUST remain complete')
            ->toBe("'" . $value . "'");
    }
}
