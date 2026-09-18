<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\OutputCapture;

use function Greenlight\expect;

final class OutputCaptureMinimumDiagnosticsTest
{
    #[Test]
    public function oneDiagnosticBoundRetainsTheFirstAndReportsTruncation(): void
    {
        $capture = new OutputCapture(maxDiagnostics: 1);
        $capture->start();

        try {
            \trigger_error('first diagnostic', \E_USER_NOTICE);
            \trigger_error('second diagnostic', \E_USER_NOTICE);
        } finally {
            $captured = $capture->stop();
        }

        expect($captured->diagnostics)
            ->because('the minimum diagnostic bound MUST retain only the first entry')
            ->toHaveCount(1);
        expect($captured->diagnostics[0]->message)
            ->toBe('first diagnostic');
        expect($captured->diagnosticsTruncated)
            ->toBeTrue();
    }
}
