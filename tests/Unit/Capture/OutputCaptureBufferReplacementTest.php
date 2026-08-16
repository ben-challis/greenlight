<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Capture\OutputCapture;
use Greenlight\Expect\Expect;

final readonly class OutputCaptureBufferReplacementTest
{
    #[Test]
    public function stopPreservesAReplacementBufferAtTheCapturedLevel(): void
    {
        $baseline = \ob_get_level();
        $capture = new OutputCapture();
        $capture->start();

        echo 'captured';
        \ob_end_flush();
        \ob_start();
        echo 'replacement';

        $captured = $capture->stop();
        $levelAfterStop = \ob_get_level();
        $replacement = $levelAfterStop > $baseline ? \ob_get_clean() : null;

        Expect::that($captured->stdout)
            ->because('closing the capture buffer MUST preserve the output collected before replacement')
            ->toBe('captured');
        Expect::that($levelAfterStop)
            ->because('stop MUST leave a replacement buffer at the captured stack level open')
            ->toBe($baseline + 1);
        Expect::that($replacement)
            ->because('stop MUST preserve content in the replacement buffer')
            ->toBe('replacement');
        Expect::that(\ob_get_level())
            ->because('the test MUST restore the output-buffer baseline')
            ->toBe($baseline);
    }
}
