<?php

declare(strict_types=1);

namespace Greenlight\Test;

use Greenlight\Internal\Text\Wildcard;

/**
 * Defines resolved rules that select tests and an optional execution-plan shard.
 *
 * @internal
 */
final readonly class TestSelection
{
    /** @param array{int, int}|null $shard A 1-based shard index and total shard count. */
    public function __construct(
        public TestInclusions $include = new TestInclusions(),
        public TestExclusions $exclude = new TestExclusions(),
        public ?array $shard = null,
    ) {}

    /** @param list<non-empty-string> $ids */
    public function withExactIds(array $ids): self
    {
        return new self($this->include->withExactIds($ids), $this->exclude, $this->shard);
    }

    /** @param list<non-empty-string> $paths */
    public function withExcludedPaths(array $paths): self
    {
        return new self($this->include, $this->exclude->withPaths($paths), $this->shard);
    }

    /** @param list<string> $groups */
    public function accepts(string $class, string $method, array $groups, string $path): bool
    {
        if ($this->include->groups !== [] && !$this->anyGroupMatches($groups, $this->include->groups)) {
            return false;
        }

        if ($this->anyGroupMatches($groups, $this->exclude->groups)) {
            return false;
        }

        if ($this->include->classes !== [] && !$this->anyNameMatches($class, $this->include->classes)) {
            return false;
        }

        if ($this->anyNameMatches($class, $this->exclude->classes)) {
            return false;
        }

        if ($this->include->methods !== [] && !$this->anyNameMatches($method, $this->include->methods)) {
            return false;
        }

        if ($this->anyNameMatches($method, $this->exclude->methods)) {
            return false;
        }

        if ($this->include->paths !== [] && !$this->anyPrefixMatches($path, $this->include->paths)) {
            return false;
        }

        return !$this->anyPrefixMatches($path, $this->exclude->paths);
    }

    public function acceptsId(string $renderedId): bool
    {
        if ($this->include->idPatterns === [] && $this->include->exactIds === []) {
            return true;
        }

        if (\in_array($renderedId, $this->include->exactIds, true)) {
            return true;
        }

        return \array_any($this->include->idPatterns, static fn(string $pattern): bool => Wildcard::matches($renderedId, $pattern, caseInsensitive: true));
    }

    /**
     * @param list<string> $groups
     * @param list<non-empty-string> $filters
     */
    private function anyGroupMatches(array $groups, array $filters): bool
    {
        return \array_any($filters, static fn(string $filter): bool => \in_array($filter, $groups, true));
    }

    /** @param list<non-empty-string> $filters */
    private function anyNameMatches(string $name, array $filters): bool
    {
        return \array_any($filters, static fn(string $filter): bool => Wildcard::matches($name, $filter, caseInsensitive: false));
    }

    /** @param list<non-empty-string> $prefixes */
    private function anyPrefixMatches(string $path, array $prefixes): bool
    {
        return \array_any($prefixes, static fn(string $prefix): bool => \str_starts_with($path, $prefix));
    }
}
