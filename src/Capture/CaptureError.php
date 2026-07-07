<?php

declare(strict_types=1);

namespace Greenlight\Capture;

/**
 * Raised when the capture lifecycle is misused.
 *
 * alreadyStarted() covers starting a capture that is already active, and
 * notStarted() covers stopping one that was never started. Both indicate a
 * bug in the calling framework code, never in user tests.
 *
 * @internal
 */
final class CaptureError extends \LogicException
{
    public static function alreadyStarted(): self
    {
        return new self('Output capture is already active. Call stop() before starting another capture window.');
    }

    public static function notStarted(): self
    {
        return new self('Output capture is not active. Call start() before stop().');
    }
}
