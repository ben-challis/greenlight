<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class IntersectionTypeMatcher implements TypedArgumentMatcher
{
    private ?ArgumentType $argumentType;

    /** @var non-empty-list<TypeMatcher> */
    private array $matchers;

    /**
     * @param non-empty-list<string> $types
     * @throws InvalidDoubleUsage
     */
    public function __construct(private array $types)
    {
        $matchers = [];

        foreach ($types as $type) {
            TypeMatcher::assertKnown('intersection', $type);
            $matchers[] = new TypeMatcher($type);
        }

        $this->matchers = $matchers;

        $this->argumentType = ArgumentType::fromIntersectionTypeNames($types);
    }

    public function matches(mixed $value): bool
    {
        return \array_all(
            $this->matchers,
            static fn(TypeMatcher $matcher): bool => $matcher->matches($value),
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
