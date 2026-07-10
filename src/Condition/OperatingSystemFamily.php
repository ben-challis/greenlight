<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the running operating system family matches, compared
 * case-insensitively against PHP_OS_FAMILY.
 *
 * Valid families are Windows, BSD, Darwin, Solaris, Linux, and unknown.
 */
final readonly class OperatingSystemFamily implements Condition
{
    public function __construct(
        private string $family,
    ) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \strcasecmp($this->family, \PHP_OS_FAMILY) === 0;
    }
}
