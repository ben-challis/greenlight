<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ValueRenderer;
use Greenlight\Tests\Fixture\Expect\StaticPropertyObject;

use function Greenlight\expect;

final readonly class ValueRendererStaticPropertyTest
{
    #[Test]
    public function objectRenderingExcludesStaticProperties(): void
    {
        expect(new ValueRenderer()->render(new StaticPropertyObject()))
            ->because('object diagnostics MUST contain only instance state')
            ->toBe(StaticPropertyObject::class . ' {local: 2}');
    }
}
