<?php

declare(strict_types=1);

namespace Greenlight\Condition;

/** Passes when `PHP_VERSION` is at least the specified version, as compared by `version_compare()`. */
final readonly class PhpVersionAtLeast implements Condition
{
    /**
     * @var non-empty-string
     */
    private string $version;

    /**
     * @param non-empty-string $version
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $version)
    {
        if ($version === '') {
            throw new \InvalidArgumentException('PHP version cannot be empty.');
        }

        $this->version = $version;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \version_compare(\PHP_VERSION, $this->version, '>=');
    }
}
