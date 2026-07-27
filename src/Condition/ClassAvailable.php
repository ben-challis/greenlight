<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

final readonly class ClassAvailable implements Condition
{
    /**
     * The class name has the string type because the class can be absent from
     * the current environment.
     *
     * @param non-empty-string $class
     */
    public function __construct(private string $class) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \class_exists($this->class);
    }
}
