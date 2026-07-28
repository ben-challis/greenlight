<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\AfterFailureContinues;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class AfterFailureContinuesTest
{
    #[Test]
    public function passesUntilTeardown(): void
    {
        TraceLog::add('test');
    }

    #[After]
    public function finalCleanup(): void
    {
        TraceLog::add('final cleanup');
    }

    #[After]
    public function failingCleanup(): never
    {
        TraceLog::add('failing cleanup');

        throw new \RuntimeException('after broke');
    }
}
