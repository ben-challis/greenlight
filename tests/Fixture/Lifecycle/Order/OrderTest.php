<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Order;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class OrderTest
{
    public function __construct()
    {
        TraceLog::add('construct');
    }

    #[Before]
    public function firstBefore(): void
    {
        TraceLog::add('before1');
    }

    #[Before]
    public function secondBefore(): void
    {
        TraceLog::add('before2');
    }

    #[Test]
    public function theTest(): void
    {
        TraceLog::add('test');
    }

    #[After]
    public function firstAfter(): void
    {
        TraceLog::add('after1');
    }

    #[After]
    public function secondAfter(): void
    {
        TraceLog::add('after2');
    }
}
