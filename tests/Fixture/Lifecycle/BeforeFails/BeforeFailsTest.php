<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\BeforeFails;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class BeforeFailsTest
{
    #[Before]
    public function breaks(): never
    {
        TraceLog::add('before');

        throw new \RuntimeException('before broke');
    }

    #[Test]
    public function neverRuns(): void
    {
        TraceLog::add('test');
    }

    #[After]
    public function stillRuns(): void
    {
        TraceLog::add('after');
    }
}
