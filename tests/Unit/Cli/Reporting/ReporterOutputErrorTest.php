<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Reporting\ReporterOutputError;
use Greenlight\Expect\Expect;

final class ReporterOutputErrorTest
{
    #[Test]
    public function directoryFailureIncludesThePathAndReason(): void
    {
        Expect::that(ReporterOutputError::directoryCreationFailed('/project/reports', 'Permission denied')->getMessage())
            ->toBe('Greenlight could not create reporter output directory "/project/reports": Permission denied.');
    }

    #[Test]
    public function fileFailureIncludesThePathAndReason(): void
    {
        Expect::that(ReporterOutputError::fileOpenFailed('/project/report.xml', 'Permission denied')->getMessage())
            ->toBe('Greenlight could not open reporter output file "/project/report.xml": Permission denied.');
    }
}
