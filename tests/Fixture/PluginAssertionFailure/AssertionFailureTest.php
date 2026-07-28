<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\PluginAssertionFailure;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class AssertionFailureTest
{
    #[Test]
    public function fails(): void
    {
        Expect::that(false)
            ->because('intentional assertion failure')
            ->toBeTrue();
    }
}
