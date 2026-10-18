<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Internal\Php\Arrays;

/** @internal */
final readonly class IntersectionTypeMatcher implements TypedArgumentMatcher
{
    private ?ArgumentType $argumentType;

    /** @param non-empty-list<string> $types */
    public function __construct(private array $types)
    {
        $this->argumentType = ArgumentType::fromIntersectionTypeNames($types);
    }

    public function matches(mixed $value): bool
    {
        return Arrays::all(
            $this->types,
            static fn(string $type): bool => TypeMatcher::matchesType($value, $type),
        );
    }

    public function describe(): string
    {
        return 'intersection(' . \implode(', ', $this->types) . ')';
    }

    public function argumentType(): ?ArgumentType
    {
        return $this->argumentType;
    }
}
