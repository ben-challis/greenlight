<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Applies a sequence of argument matchers as one constraint.
 *
 * @template-covariant TValue
 *
 * @implements ArgumentMatcher<TValue>
 *
 * @internal
 */
final readonly class AllOf implements ArgumentMatcher
{
    /**
     * @param non-empty-list<ArgumentMatcher<TValue>> $matchers
     */
    public function __construct(private array $matchers) {}

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
}
