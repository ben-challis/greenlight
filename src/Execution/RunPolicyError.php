<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Execution\Plugin\PluginRuntimeError;

/**
 * A run acceptance policy cannot complete its command-side operation.
 *
 * @internal
 */
final class RunPolicyError extends \RuntimeException
{
    public static function fromRuntime(PluginRuntimeError $cause): self
    {
        return new self($cause->getMessage(), previous: $cause);
    }
}
