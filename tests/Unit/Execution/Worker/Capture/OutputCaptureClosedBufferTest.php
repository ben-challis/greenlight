<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\OutputCapture;
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
            ->toBe('captured before close');
        Expect::that(\ob_get_level())
            ->because('stop MUST preserve the output-buffer baseline')
            ->toBe($baseline);
    }
}
