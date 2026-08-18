<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class TypeMatcher implements ArgumentMatcher
{
    /**
     * @throws DoublesError
     */
    public function __construct(private string $type)
    {
        if (\trim($type) === '') {
            throw DoublesError::invalidArgumentType();
        }
    }

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
