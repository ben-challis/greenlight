<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CrashSuite;

use Greenlight\Attribute\Test;

final class CrashyTest
{
    #[Test]
    public function passes(): void {}

    #[Test]
    public function killsTheWholeProcess(): never
    {
        // Simulates a segfault or fatal: the worker dies mid-test with no
        // TestFinished event. Never run this suite in-process.
        exit(9);
    }
}
