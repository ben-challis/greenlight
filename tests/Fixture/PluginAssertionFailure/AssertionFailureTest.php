<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PluginAssertionFailure;

use Greenlight\Attribute\Test;


use function Greenlight\expect;

final class AssertionFailureTest
{
    #[Test]
    public function fails(): void
    {
        expect(false)
            ->because('intentional assertion failure')
            ->toBeTrue();
    }
}
