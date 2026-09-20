<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class UnionTypeMatcher implements TypedArgumentMatcher
{
    private ?ArgumentType $argumentType;

    /** @param non-empty-list<string> $types */
    public function __construct(private array $types)
    {
        $this->argumentType = ArgumentType::fromUnionTypeNames($types);
    }

    public function matches(mixed $value): bool
    {
        return \array_any(
            $this->types,
            static fn(string $type): bool => TypeMatcher::matchesType($value, $type),
        );
    }

    public function describe(): string
    {
        return 'union(' . \implode(', ', $this->types) . ')';
    }

    public function argumentType(): ?ArgumentType
    {
        return $this->argumentType;
    }
}
