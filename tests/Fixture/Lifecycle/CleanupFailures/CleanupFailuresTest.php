<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\CleanupFailures;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final readonly class CleanupFailuresTest
{
    public function __construct(private Cleanup $cleanup) {}

    #[Test]
    public function passesBeforeCleanupFails(): void
    {
        $this->cleanup->defer(static fn() => throw new \RuntimeException('cleanup broke after pass'));
    }

    #[Test]
    public function errorsBeforeCleanupFails(): never
    {
        $this->cleanup->defer(static function (): void {
            TraceLog::add('last cleanup');
        });
        $this->cleanup->defer(static function (): never {
            TraceLog::add('failing cleanup');

            throw new \RuntimeException('cleanup broke after error');
        });
        $this->cleanup->defer(static function (): void {
            TraceLog::add('first cleanup');
        });

        throw new \RuntimeException('test broke');
    }

    #[Test]
    public function skipsBeforeCleanupFails(): never
    {
        $this->cleanup->defer(static fn() => throw new \RuntimeException('cleanup broke after skip'));

        throw new SkipTest('skip requested');
    }

    #[Test]
    public function passesBeforeCleanupExpectationFails(): void
    {
        $this->cleanup->defer(static function (): void {
            Expect::that('actual')->toBe('expected');
        });
    }
}
