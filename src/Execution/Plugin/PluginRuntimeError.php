<?php

declare(strict_types=1);

namespace Greenlight\Execution\Plugin;

use Greenlight\Result\Outcome;
use Greenlight\Test\TestId;

/**
 * A plugin cannot complete a runtime operation.
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
    public static function creationFailed(string $plugin, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Plugin "%s" caused an error during creation: %s',
            $plugin,
            $cause->getMessage(),
        ), $cause);
    }

    /** @param class-string $plugin */
    public static function emptyRunPolicyFailure(string $plugin): self
    {
        return new self(\sprintf(
            'Plugin "%s" returned an empty failure message from failureMessage().',
            $plugin,
        ));
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

    /** @param class-string $plugin */
    public static function addedUnknownTest(string $plugin, TestId $test): self
    {
        return new self(\sprintf(
            'Plugin "%s" added unknown test "%s" during transformTestPlan(). A plan transformer MAY only remove or reorder selected tests.',
            $plugin,
            $test,
        ));
    }
}
