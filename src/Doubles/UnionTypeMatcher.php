<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class UnionTypeMatcher implements TypedArgumentMatcher
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
            TypeMatcher::assertKnown('union', $type);
            $matchers[] = new TypeMatcher($type);
        }

        $this->matchers = $matchers;

        $this->argumentType = ArgumentType::fromUnionTypeNames($types);
    }

    public function matches(mixed $value): bool
    {
        return \array_any(
            $this->matchers,
            static fn(TypeMatcher $matcher): bool => $matcher->matches($value),
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
