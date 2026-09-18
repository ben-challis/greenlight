<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ValueRenderer;

use function Greenlight\expect;

final readonly class ValueRendererStringBoundaryTest
{
    #[Test]
    public function aStringAtTheCharacterLimitRemainsComplete(): void
    {
        $value = \str_repeat('x', 120);

        expect(new ValueRenderer()->render($value))
            ->because('a diagnostic string at the limit MUST remain complete')
            ->toBe("'" . $value . "'");
    }
}
