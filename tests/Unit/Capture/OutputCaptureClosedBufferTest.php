<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Capture\OutputCapture;
use Greenlight\Expect\Expect;

final readonly class OutputCaptureClosedBufferTest
{
    #[Test]
    public function stopToleratesAUserClosingTheCaptureBuffer(): void
    {
        $baseline = \ob_get_level();
        $capture = new OutputCapture();
        $capture->start();

        echo 'captured before close';
        \ob_end_flush();

        $captured = $capture->stop();

        Expect::that($captured->stdout)
            ->because('stop MUST preserve output after user code closes the capture buffer')
            ->toBe('captured before close')
            ->and(\ob_get_level())
            ->because('stop MUST preserve the output-buffer baseline')
            ->toBe($baseline);
    }
}
