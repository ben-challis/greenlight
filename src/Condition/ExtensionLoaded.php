<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

final readonly class ExtensionLoaded implements Condition
{
    public function __construct(private string $extension) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \extension_loaded($this->extension);
    }
}
