<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\CleanupRetries;

use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Test;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class CleanupRetriesTest
{
    public static int $attempts = 0;

    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    #[Retry(times: 1)]
    public function eachAttemptReceivesCleanup(): void
    {
        ++self::$attempts;
        $attempt = self::$attempts;
        TraceLog::add('test ' . $attempt);
        $this->cleanup->defer(static fn() => TraceLog::add('cleanup ' . $attempt));

        if ($attempt === 1) {
            throw new \RuntimeException('retry the test');
        }
    }
}
