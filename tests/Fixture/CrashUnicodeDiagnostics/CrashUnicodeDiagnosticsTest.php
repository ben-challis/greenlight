<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\CrashUnicodeDiagnostics;

use Greenlight\Attribute\Test;

final class CrashUnicodeDiagnosticsTest
{
    #[Test]
    public function writesUnicodeDiagnosticsThenExits(): never
    {
        \fwrite(\STDERR, 'xx€' . \str_repeat('y', 2046));

        exit(23);
    }
}
