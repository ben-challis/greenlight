<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class PredicateMatcher implements ArgumentMatcher
{
    /** @param \Closure(mixed): mixed $predicate */
    public function __construct(
        private \Closure $predicate,
        private string $description,
    ) {}

    public function matches(mixed $value): bool
    {
        return ($this->predicate)($value) === true;
    }

    public function describe(): string
    {
        return \sprintf('predicate(%s)', $this->description);
    }
}
