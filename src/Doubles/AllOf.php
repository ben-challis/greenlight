<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Applies a sequence of argument matchers as one constraint.
 *
 * @template-covariant TValue
 *
 * @implements TypedArgumentMatcher<TValue>
 *
 * @internal
 */
final readonly class AllOf implements TypedArgumentMatcher
{
    private ?ArgumentType $argumentType;

    /**
     * @param non-empty-list<ArgumentMatcher<TValue>> $matchers
     */
    public function __construct(private array $matchers)
    {
        $types = \array_values(\array_filter(\array_map(
            static fn(ArgumentMatcher $matcher): ?ArgumentType => $matcher instanceof TypedArgumentMatcher
                ? $matcher->argumentType()
                : null,
            $matchers,
        )));
        $this->argumentType = $types === []
            ? null
            : \array_reduce(
                \array_slice($types, 1),
                static fn(ArgumentType $combined, ArgumentType $type): ArgumentType => $combined->intersect($type),
                $types[0],
            );
    }

    public function matches(mixed $value): bool
    {
        return \array_all($this->matchers, static fn(ArgumentMatcher $matcher): bool => $matcher->matches($value));
    }

    public function describe(): string
    {
        return 'allOf(' . \implode(', ', \array_map(
            static fn(ArgumentMatcher $matcher): string => $matcher->describe(),
            $this->matchers,
        )) . ')';
    }

    public function argumentType(): ?ArgumentType
    {
        return $this->argumentType;
    }
}
