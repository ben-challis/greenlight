<?php

declare(strict_types=1);

namespace Greenlight\Condition;

final readonly class ExtensionMissing implements Condition
{
    /**
     * @var non-empty-string
     */
    private string $extension;

    /**
     * @param non-empty-string $extension
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $extension)
    {
        if ($extension === '') {
            throw new \InvalidArgumentException('Extension name cannot be empty.');
        }

        $this->extension = $extension;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return !\extension_loaded($this->extension);
    }
}
