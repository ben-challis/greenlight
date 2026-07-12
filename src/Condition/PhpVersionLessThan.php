<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the running PHP version is below the given version.
 */
final readonly class PhpVersionLessThan implements Condition
{
    public function __construct(private string $version) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \version_compare(\PHP_VERSION, $this->version, '<');
    }
}
