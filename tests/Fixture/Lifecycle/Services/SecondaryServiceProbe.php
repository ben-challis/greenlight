<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Services;

use Greenlight\Doubles\Fake;
use Greenlight\Harness\Disposable;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class SecondaryServiceProbe implements Disposable, Fake
{
    private readonly string $name;

    public function __construct()
    {
        $this->name = 'secondary';
        TraceLog::add($this->name . ':created');
    }

    public function touch(): void
    {
        TraceLog::add($this->name . ':touched');
    }

    #[\Override]
    public function dispose(): void
    {
        TraceLog::add($this->name . ':disposed');
    }
}
