<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Result\Outcome;
use Greenlight\Test\TestId;

/**
 * A worker plugin cannot complete a test lifecycle operation.
 *
 * @internal
 */
final class PluginRuntimeError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /** @param class-string $plugin */
    public static function hookFailed(string $plugin, string $hook, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Plugin "%s" caused an error during %s(): %s',
            $plugin,
            $hook,
            $cause->getMessage(),
        ), $cause);
    }

    /** @param class-string $plugin */
    public static function changedTestIdentity(
        string $plugin,
        TestId $before,
        TestId $after,
        string $hook = 'afterTest',
    ): self {
        return new self(\sprintf(
            'Plugin "%s" changed the test identity during %s() from "%s" to "%s".',
            $plugin,
            $hook,
            $before,
            $after,
        ));
    }

    /** @param class-string $plugin */
    public static function changedOutcome(string $plugin, Outcome $before, Outcome $after): self
    {
        return new self(\sprintf(
            'Plugin "%s" changed the outcome from %s to %s without a new transformation-log entry from withOutcome().',
            $plugin,
            $before->value,
            $after->value,
        ));
    }
}
