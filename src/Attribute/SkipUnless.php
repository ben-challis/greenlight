<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Core\Condition;

/**
 * Evaluates the condition in the worker. Constructor arguments must be
 * scalars or null so they can cross the worker boundary.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class SkipUnless
{
    /**
     * @var list<mixed>
     */
    public array $arguments;

    /**
     * @param class-string<Condition> $condition
     */
    public function __construct(
        public string $condition,
        mixed ...$arguments,
    ) {
        $this->arguments = \array_values($arguments);
    }
}
