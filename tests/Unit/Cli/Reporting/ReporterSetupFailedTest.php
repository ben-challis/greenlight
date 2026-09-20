<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Reporting\ReporterSetupFailed;

use function Greenlight\expect;

final class ReporterSetupFailedTest
{
    #[Test]
    public function directoryFailureIncludesThePathAndReason(): void
    {
        expect(ReporterSetupFailed::directoryCreationFailed('/project/reports', 'Permission denied')->getMessage())
            ->toBe('Greenlight could not create reporter output directory "/project/reports": Permission denied.');
    }

    #[Test]
    public function fileFailureIncludesThePathAndReason(): void
    {
        expect(ReporterSetupFailed::fileOpenFailed('/project/report.xml', 'Permission denied')->getMessage())
            ->toBe('Greenlight could not open reporter output file "/project/report.xml": Permission denied.');
    }
}
