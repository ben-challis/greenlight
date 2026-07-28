<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Services;

use Greenlight\Harness\Disposable;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class ServiceProbe implements Disposable
{
    private static int $instances = 0;

    public readonly int $number;

    public function __construct()
    {
        $this->number = ++self::$instances;
        TraceLog::add('probe' . $this->number . ':created');
    }

    public function touch(): void
    {
        TraceLog::add('probe' . $this->number . ':touched');
    }

    #[\Override]
    public function dispose(): void
    {
        TraceLog::add('probe' . $this->number . ':disposed');
    }

    public static function reset(): void
    {
        self::$instances = 0;
    }
}
