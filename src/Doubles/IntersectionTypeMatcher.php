<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class IntersectionTypeMatcher implements ArgumentMatcher
{
    /** @param non-empty-list<string> $types */
    public function __construct(private array $types) {}

    public function matches(mixed $value): bool
    {
        return \array_all(
            $this->types,
            static fn(string $type): bool => TypeMatcher::matchesType($value, $type),
        );
    }

    public function describe(): string
    {
        return 'intersection(' . \implode(', ', $this->types) . ')';
    }
}
