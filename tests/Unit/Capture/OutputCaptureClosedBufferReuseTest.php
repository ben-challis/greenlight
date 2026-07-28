<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Capture\OutputCapture;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Expect\Expect;

final readonly class OutputCaptureClosedBufferReuseTest
{
    #[Test]
    public function captureCanBeReusedAfterItsBufferWasClosed(): void
    {
        [$first, $second, $levelAfterSecondStop, $baseline] = $this->captureTwice();

        Expect::that($first->stdout)
            ->because('closing the first capture buffer MUST preserve its output')
            ->toBe('first')
            ->and($second->stdout)
            ->because('a reused capture MUST collect output in its second window')
            ->toBe('second')
            ->and($levelAfterSecondStop)
            ->because('the second stop MUST restore the original output-buffer level')
            ->toBe($baseline);
    }

    /**
     * @return array{CapturedOutput, CapturedOutput, int, int}
     */
    private function captureTwice(): array
    {
        $baseline = \ob_get_level();
        $capture = new OutputCapture();

        try {
            $capture->start();
            echo 'first';
            \ob_end_flush();
            $first = $capture->stop();

            $capture->start();
            echo 'second';
            $second = $capture->stop();

            return [$first, $second, \ob_get_level(), $baseline];
        } finally {
            while (\ob_get_level() > $baseline) {
                \ob_end_clean();
            }
        }
    }
}
