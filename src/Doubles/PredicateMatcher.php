<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Matches values a caller-supplied closure accepts.
 *
 * @internal obtain via Argument::predicate()
 */
final readonly class PredicateMatcher implements ArgumentMatcher
{
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
