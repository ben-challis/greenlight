<?php

declare(strict_types=1);

namespace Greenlight\Condition;

use Greenlight\Core\Condition;

/**
 * Satisfied when the named class exists or can be autoloaded.
 */
final readonly class ClassAvailable implements Condition
{
    /**
     * The name is a plain string, not a class-string, because the class it
     * names may legitimately not exist in the current environment.
     *
     * @param non-empty-string $class
     */
    public function __construct(
        private string $class,
    ) {}

    #[\Override]
    public function isSatisfied(): bool
    {
        return \class_exists($this->class);
    }
}
