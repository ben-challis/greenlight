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
     * @throws \InvalidArgumentException
     */
    public function __construct(string $class)
    {
        if ($class === '') {
            throw new \InvalidArgumentException('Class name MUST NOT be empty.');
        }

        $this->class = $class;
    }

    #[\Override]
    public function isSatisfied(): bool
    {
        return \class_exists($this->class);
    }
}
