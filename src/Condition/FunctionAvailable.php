<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

final readonly class FunctionAvailable implements Condition
{
    public function __construct(private string $function) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \function_exists($this->function);
    }
}
