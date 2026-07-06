<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

/**
 * Pure, composable selection rules applied to discovered tests before data-set
 * expansion. Include lists are OR-ed within a dimension and AND-ed across
 * dimensions; exclude lists always win. Class and method filters match by
 * substring, or by shell-style wildcard when the filter contains "*" or "?".
 * Path filters match by path prefix.
 *
 * @internal
 */
final readonly class Filter
{
    /**
     * @param list<non-empty-string> $includeGroups
     * @param list<non-empty-string> $excludeGroups
     * @param list<non-empty-string> $includeClasses
     * @param list<non-empty-string> $excludeClasses
     * @param list<non-empty-string> $includeMethods
     * @param list<non-empty-string> $excludeMethods
     * @param list<non-empty-string> $includePaths
     * @param list<non-empty-string> $excludePaths
     */
    public function __construct(
        public array $includeGroups = [],
        public array $excludeGroups = [],
        public array $includeClasses = [],
        public array $excludeClasses = [],
        public array $includeMethods = [],
        public array $excludeMethods = [],
        public array $includePaths = [],
        public array $excludePaths = [],
    ) {}

    public static function all(): self
    {
        return new self();
    }

    /**
     * @param list<string> $groups
     */
    public function accepts(string $class, string $method, array $groups, string $path): bool
    {
        if ($this->includeGroups !== [] && !$this->anyGroupMatches($groups, $this->includeGroups)) {
            return false;
        }

        if ($this->anyGroupMatches($groups, $this->excludeGroups)) {
            return false;
        }

        if ($this->includeClasses !== [] && !$this->anyNameMatches($class, $this->includeClasses)) {
            return false;
        }

        if ($this->anyNameMatches($class, $this->excludeClasses)) {
            return false;
        }

        if ($this->includeMethods !== [] && !$this->anyNameMatches($method, $this->includeMethods)) {
            return false;
        }

        if ($this->anyNameMatches($method, $this->excludeMethods)) {
            return false;
        }

        if ($this->includePaths !== [] && !$this->anyPrefixMatches($path, $this->includePaths)) {
            return false;
        }

        return !$this->anyPrefixMatches($path, $this->excludePaths);
    }

    /**
     * @param list<string> $groups
     * @param list<non-empty-string> $filters
     */
    private function anyGroupMatches(array $groups, array $filters): bool
    {
        return array_any($filters, static fn(string $filter): bool => \in_array($filter, $groups, true));
    }

    /**
     * @param list<non-empty-string> $filters
     */
    private function anyNameMatches(string $name, array $filters): bool
    {
        return array_any($filters, fn(string $filter): bool => $this->nameMatches($name, $filter));
    }

    private function nameMatches(string $name, string $filter): bool
    {
        if (!\str_contains($filter, '*') && !\str_contains($filter, '?')) {
            return \str_contains($name, $filter);
        }

        $pattern = '/^' . \strtr(\preg_quote($filter, '/'), ['\*' => '.*', '\?' => '.']) . '$/';

        return \preg_match($pattern, $name) === 1;
    }

    /**
     * @param list<non-empty-string> $prefixes
     */
    private function anyPrefixMatches(string $path, array $prefixes): bool
    {
        return array_any($prefixes, static fn(string $prefix): bool => \str_starts_with($path, $prefix));
    }
}
