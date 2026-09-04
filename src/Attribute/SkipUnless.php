<?php

declare(strict_types=1);

namespace Greenlight\Attribute;

use Greenlight\Condition\Condition;

/**
 * A worker evaluates the condition. Use only scalar values or null for
 * constructor arguments. Use finite float values.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::TARGET_CLASS)]
final readonly class SkipUnless
{
    /**
     * @var class-string<Condition>
     */
    public string $condition;

    /**
     * @var list<mixed>
     */
    public array $arguments;

    /**
     * @param class-string<Condition> $condition
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $condition,
        mixed ...$arguments,
    ) {
        if (!\is_a($condition, Condition::class, true)) {
            throw new \InvalidArgumentException(
                'Set the SkipUnless condition to an instantiable Condition class.',
            );
        }

        $reflection = new \ReflectionClass($condition);

        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException(
                'Set the SkipUnless condition to an instantiable Condition class.',
            );
        }

        $this->condition = $condition;

        foreach ($arguments as $argument) {
            if (\is_float($argument) && !\is_finite($argument)) {
                throw new \InvalidArgumentException('Use finite floats in SkipUnless arguments.');
            }
        }

        $this->arguments = \array_values($arguments);
    }
}
