<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BootstrapFailing;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Test;

final class FailingTest
{
    #[Test]
    public function failsIntentionally(): never
    {
        throw new \RuntimeException('intentional failure');
    }

    #[After]
    public function markAfter(): void
    {
        echo "marker:after\n";
    }
}
