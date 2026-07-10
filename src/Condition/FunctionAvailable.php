<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the named function exists, whether built-in or user-defined.
 */
final readonly class FunctionAvailable implements Condition
{
    public function __construct(
        private string $function,
    ) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \function_exists($this->function);
    }
}
