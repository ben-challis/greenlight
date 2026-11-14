<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\OutputCapture;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\DiagnosticSeverity;

use function Greenlight\expect;

final readonly class OutputCaptureBufferReplacementTest
{
    #[Test]
    public function stopRemovesAReplacementBufferAndKeepsItsText(): void
    {
        $baseline = \ob_get_level();
        [$captured, $levelAfterStop] = $this->captureWith(static function (): void {
            echo 'captured';
            \ob_end_flush();
            \ob_start();
            echo ' replacement';
        });

        expect($captured->stdout)
            ->because('stop MUST keep the output from the closed capture buffer and the replacement buffer')
            ->toBe('captured replacement');
        expect($levelAfterStop)
            ->because('stop MUST remove a replacement buffer that the test opened')
            ->toBe($baseline);
    }

    #[Test]
    public function stopUnwindsAfterTheTestClearsEveryBufferAndOpensANewOne(): void
    {
        $baseline = \ob_get_level();
        $closeLine = __LINE__ + 5;
        [$captured, $levelAfterStop] = $this->captureWith(static function () use ($baseline): void {
            echo 'discarded';

            while (\ob_get_level() > $baseline) {
                \ob_end_clean();
            }

            \ob_start();
            echo 'kept';
        });

        expect($levelAfterStop)
            ->because('stop MUST NOT leave a buffer open after the test replaces the capture buffer')
            ->toBe($baseline);
        expect($captured->stdout)
            ->because('stop MUST keep the text of the replacement buffer')
            ->toEndWith('kept');
        expect($captured->diagnostics)->toHaveCount(1);
        expect($captured->diagnostics[0]->severity)
            ->because('a closed capture buffer MUST produce a warning diagnostic')
            ->toBe(DiagnosticSeverity::Warning);
        expect($captured->diagnostics[0]->message)->toStartWith('The test closed the output capture buffer.');
        expect($captured->diagnostics[0]->file)
            ->because('the diagnostic MUST name the call that closed the buffer')
            ->toBe(__FILE__);
        expect($captured->diagnostics[0]->line)->toBe($closeLine);
    }

    /**
     * @param \Closure(): void $test
     *
     * @return array{CapturedOutput, int}
     */
    private function captureWith(\Closure $test): array
    {
        $baseline = \ob_get_level();
        $capture = new OutputCapture();

        try {
            $capture->start();
            $test();
            $captured = $capture->stop();

            return [$captured, \ob_get_level()];
        } finally {
            while (\ob_get_level() > $baseline) {
                \ob_end_clean();
            }
        }
    }
}
