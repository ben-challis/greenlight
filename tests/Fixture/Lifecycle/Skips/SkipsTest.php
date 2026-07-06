<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Skips;

use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;

final class SkipsTest
{
    public function __construct()
    {
        TraceLog::add('construct');
    }

    #[Test]
    #[Skip('not today')]
    public function skippedUnconditionally(): void
    {
        TraceLog::add('unconditional');
    }

    #[Test]
    #[SkipUnless(NeverCondition::class)]
    public function skippedByCondition(): void
    {
        TraceLog::add('conditional');
    }

    #[Test]
    #[SkipUnless(AlwaysCondition::class)]
    public function runsWhenSatisfied(): void
    {
        TraceLog::add('satisfied');
    }
}
