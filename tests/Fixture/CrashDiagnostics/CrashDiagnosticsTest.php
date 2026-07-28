<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CrashDiagnostics;

use Greenlight\Attribute\Test;

final class CrashDiagnosticsTest
{
    #[Test]
    public function writesDiagnosticsThenExits(): never
    {
        \fwrite(\STDERR, "The worker emitted crash diagnostics.\n");

        exit(23);
    }
}
