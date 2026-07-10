<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the running PHP version is at least the given version.
 */
final readonly class PhpVersionAtLeast implements Condition
{
    public function __construct(
        private string $version,
    ) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \version_compare(\PHP_VERSION, $this->version, '>=');
    }
}
