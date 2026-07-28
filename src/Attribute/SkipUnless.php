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
     * @var class-string<Condition>
     */
    public string $condition;

    /**
     * @var list<mixed>
     */
    public array $arguments;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $condition,
        mixed ...$arguments,
    ) {
        if (!\is_a($condition, Condition::class, true)) {
            throw new \InvalidArgumentException(
                'SkipUnless condition MUST name an instantiable Condition class.',
            );
        }

        $reflection = new \ReflectionClass($condition);

        if (!$reflection->isInstantiable()) {
            throw new \InvalidArgumentException(
                'SkipUnless condition MUST name an instantiable Condition class.',
            );
        }

        $this->condition = $condition;
        $this->arguments = \array_values($arguments);
    }
}
