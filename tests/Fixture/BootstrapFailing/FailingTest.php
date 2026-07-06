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
    public function logAfter(): void
    {
        $file = \getenv('GREENLIGHT_FIXTURE_LOG');

        if (\is_string($file) && $file !== '') {
            \file_put_contents($file, "after\n", \FILE_APPEND);
        }
    }
}
