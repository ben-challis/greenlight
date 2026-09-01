<?php

declare(strict_types=1);

namespace Greenlight\Condition;

/** Compares the value to `PHP_OS_FAMILY` without case sensitivity. */
final readonly class OperatingSystemFamily implements Condition
{
    /**
     * @var non-empty-string
     */
    private string $family;

    /**
     * @param non-empty-string $family
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $family)
    {
        if ($family === '') {
            throw new \InvalidArgumentException('Operating system family MUST NOT be empty.');
        }

        $this->family = $family;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \strcasecmp($this->family, \PHP_OS_FAMILY) === 0;
    }
}
