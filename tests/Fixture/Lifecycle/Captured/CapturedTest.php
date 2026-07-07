<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Captured;

use Greenlight\Attribute\Test;

final class CapturedTest
{
    #[Test]
    public function echoesAndFails(): never
    {
        echo "noisy diagnostic output\n";
        \trigger_error('old api', \E_USER_DEPRECATED);

        throw new \RuntimeException('boom after noise');
    }

    #[Test(capture: false)]
    public function optsOutOfCapture(): void
    {
        // No echo here: with capture off it would pollute the real stream.
    }
}
