<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

/**
 * Output capture raises this error when its buffer lifecycle state is invalid.
 *
 * @internal
 */
final class CaptureError extends \LogicException
{
    private function __construct(string $message)
    {
        parent::__construct($message);
    }

    public static function alreadyStarted(): self
    {
        return new self('Output capture is already active. Call stop() before starting another capture window.');
    }

    public static function notStarted(): self
    {
        return new self('Output capture is not active. Call start() before stop().');
    }

    public static function nestedBufferCannotBeRemoved(): self
    {
        return new self('Output capture cannot stop because a nested output buffer cannot be removed.');
    }
}
