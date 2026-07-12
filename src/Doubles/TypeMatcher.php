<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Matches values by class, interface, or builtin type name.
 *
 * @internal obtain via Argument::type()
 */
final readonly class TypeMatcher implements ArgumentMatcher
{
    /**
     * @param non-empty-string $type
     */
    public function __construct(private string $type) {}

    public function matches(mixed $value): bool
    {
        if ($value instanceof $this->type) {
            return true;
        }

        return \get_debug_type($value) === $this->type;
    }

    public function describe(): string
    {
        return \sprintf('type(%s)', $this->type);
    }
}
