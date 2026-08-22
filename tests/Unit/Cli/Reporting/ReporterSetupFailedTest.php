<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Reporting\ReporterSetupFailed;
use Greenlight\Expect\Expect;

final class ReporterSetupFailedTest
{
    #[Test]
    public function directoryFailureIncludesThePathAndReason(): void
    {
        Expect::that(ReporterSetupFailed::directoryCreationFailed('/project/reports', 'Permission denied')->getMessage())
            ->toBe('Greenlight could not create reporter output directory "/project/reports": Permission denied.');
    }

    #[Test]
    public function fileFailureIncludesThePathAndReason(): void
    {
        Expect::that(ReporterSetupFailed::fileOpenFailed('/project/report.xml', 'Permission denied')->getMessage())
            ->toBe('Greenlight could not open reporter output file "/project/report.xml": Permission denied.');
    }
}
