<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Execution\Plugin\PluginRuntimeError;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Greenlight raises this error when execution cannot complete a run.
 *
 * @internal
 */
final class ExecutionFailed extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function workerFatal(
        string $workerId,
        string $message,
        string $file,
        int $line,
        ?\Throwable $previous = null,
    ): self {
        return new self(\sprintf(
            'Worker "%s" reported a fatal Greenlight error: %s (%s:%d).',
            $workerId,
            $message,
            $file,
            $line,
        ), $previous);
    }

    public static function processPool(WireCommunicationFailed $previous): self
    {
        return new self($previous->getMessage(), $previous);
    }

    public static function plugin(PluginRuntimeError $previous): self
    {
        return new self($previous->getMessage(), $previous);
    }
}
