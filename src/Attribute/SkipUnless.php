<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Core\Condition;

/**
 * A worker evaluates the condition. Because the worker protocol transfers the
 * constructor arguments, use only scalar values or null.
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
