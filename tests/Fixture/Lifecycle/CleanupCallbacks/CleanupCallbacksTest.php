<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\CleanupCallbacks;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Test;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final readonly class CleanupCallbacksTest
{
    public function __construct(
        private Cleanup $cleanup,
        private CleanupOrderProbe $probe,
    ) {}

    #[Test]
    public function registersCallbacks(): void
    {
        $probe = $this->probe;
        $this->cleanup->defer(static fn() => $probe->record('first cleanup'));
        $this->cleanup->defer(static fn() => $probe->record('second cleanup'));
        TraceLog::add('test');
    }

    #[After]
    public function recordsAfterHook(): void
    {
        TraceLog::add('after');
    }
}
