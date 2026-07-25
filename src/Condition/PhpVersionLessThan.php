<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

final readonly class PhpVersionLessThan implements Condition
{
    public function __construct(private string $version) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \version_compare(\PHP_VERSION, $this->version, '<');
    }
}
