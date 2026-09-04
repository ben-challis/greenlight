<?php

declare(strict_types=1);

namespace Greenlight\Condition;

final readonly class ClassAvailable implements Condition
{
    /**
     * The class name has the string type because the class can be absent from
     * the current environment.
     *
     * @var non-empty-string
     */
    private string $class;

    /**
     * @param non-empty-string $class
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(string $class)
    {
        if ($class === '') {
            throw new \InvalidArgumentException('Class name cannot be empty.');
        }

        $this->class = $class;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \class_exists($this->class);
    }
}
