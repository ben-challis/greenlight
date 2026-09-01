<?php

declare(strict_types=1);

namespace Greenlight\Condition;

final readonly class PhpVersionLessThan implements Condition
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
            throw new \InvalidArgumentException('PHP version MUST NOT be empty.');
        }

        $this->version = $version;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \version_compare(\PHP_VERSION, $this->version, '<');
    }
}
