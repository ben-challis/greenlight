<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Worker\OutputCapture;
use Greenlight\Expect\Expect;

final readonly class OutputCaptureCallbackFailureTest
{
    #[Test]
    public function aCallbackFailureStillClosesBuffersAndRestoresTheErrorHandler(): void
    {
        $baseline = \ob_get_level();
        $messages = [];
        $handler = static function (int $severity, string $message) use (&$messages): bool {
            $messages[] = $message;

            return true;
        };
        $previous = \set_error_handler($handler);
        $capture = new OutputCapture();
        $capture->start();
        $first = new \RuntimeException('The inner output callback failed.');
        $second = new \RuntimeException('The outer output callback failed.');
        \ob_start(static function (string $buffer, int $phase) use ($second): string {
            if (($phase & \PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                return '';
            }

            throw $second;
        });
        \ob_start(static function (string $buffer, int $phase) use ($first): string {
            if (($phase & \PHP_OUTPUT_HANDLER_CLEAN) !== 0) {
                return '';
            }

            throw $first;
        });

        try {
            Expect::that($capture->stop(...))
                ->because('capture cleanup MUST preserve the first callback failure')
                ->toThrow($first);
            $levelAfterStop = \ob_get_level();
            \trigger_error('After capture.', \E_USER_NOTICE);
        } finally {
            while (\ob_get_level() > $baseline) {
                \ob_end_clean();
            }

            do {
                $active = \set_error_handler(static fn(): bool => true);
                \restore_error_handler();

                if ($active !== $previous) {
                    \restore_error_handler();
                }
            } while ($active !== $previous);
        }

        Expect::that($levelAfterStop)
            ->because('a callback failure MUST NOT leave capture buffers active')
            ->toBe($baseline);
        Expect::that($messages)
            ->because('the previous error handler MUST receive later diagnostics')
            ->toBe(['After capture.']);

        $capture->start();
        echo 'Next capture.';
        $captured = $capture->stop();

        Expect::that($captured->stdout)->toBe('Next capture.');
    }
}
