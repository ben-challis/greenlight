<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\CleanupCallbacks;

use Greenlight\Harness\Disposable;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final readonly class CleanupOrderProbe implements Disposable
{
    public function record(string $entry): void
    {
        TraceLog::add($entry);
    }

    #[\Override]
    public function dispose(): void
    {
        TraceLog::add('fixture disposal');
    }
}
