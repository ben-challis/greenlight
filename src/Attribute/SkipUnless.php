<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Core\Condition;

/**
 * Skips the test method, or every test in the class, unless the condition is
 * satisfied.
 *
 * The condition is evaluated at execution time in the worker. Any extra
 * arguments are passed to the condition's constructor and must be scalars or
 * null so they survive the wire to parallel workers.
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
