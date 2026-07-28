<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Wildcard;

/**
 * Defines selection rules for discovered tests before data-set expansion.
 * The rules do not change state, and callers can combine them.
 *
 * A test satisfies an include dimension if it matches one include value. It
 * must satisfy each applicable dimension. An exclude value always has
 * priority.
 *
 * accepts() applies group, class, method, and path filters. Class and method
 * filters match part of the text. A filter with "*" or "?" uses a shell wildcard.
 * A path filter matches a path prefix.
 *
 * acceptsId() applies test ID filters after data-set expansion. The comparison
 * does not use letter case. It uses the same partial-text or wildcard rule.
 * Exact test IDs match the complete rendered test ID.
 *
 * @internal
 */
final readonly class Filter
{
    /** @var list<non-empty-string> */
    public array $includeGroups;

    /** @var list<non-empty-string> */
    public array $excludeGroups;

    /** @var list<non-empty-string> */
    public array $includeClasses;

    /** @var list<non-empty-string> */
    public array $excludeClasses;

    /** @var list<non-empty-string> */
    public array $includeMethods;

    /** @var list<non-empty-string> */
    public array $excludeMethods;

    /** @var list<non-empty-string> */
    public array $includePaths;

    /** @var list<non-empty-string> */
    public array $excludePaths;

    /** @var list<non-empty-string> */
    public array $includeIds;

    /** @var list<non-empty-string> */
    public array $includeExactIds;

    /**
     * @param list<string> $includeGroups
     * @param list<string> $excludeGroups
     * @param list<string> $includeClasses
     * @param list<string> $excludeClasses
     * @param list<string> $includeMethods
     * @param list<string> $excludeMethods
     * @param list<string> $includePaths
     * @param list<string> $excludePaths
     * @param list<string> $includeIds
     * @param list<string> $includeExactIds
     */
    public function __construct(
        array $includeGroups = [],
        array $excludeGroups = [],
        array $includeClasses = [],
        array $excludeClasses = [],
        array $includeMethods = [],
        array $excludeMethods = [],
        array $includePaths = [],
        array $excludePaths = [],
        array $includeIds = [],
        array $includeExactIds = [],
    ) {
        $this->includeGroups = $this->validateValues('includeGroups', $includeGroups);
        $this->excludeGroups = $this->validateValues('excludeGroups', $excludeGroups);
        $this->includeClasses = $this->validateValues('includeClasses', $includeClasses);
        $this->excludeClasses = $this->validateValues('excludeClasses', $excludeClasses);
        $this->includeMethods = $this->validateValues('includeMethods', $includeMethods);
        $this->excludeMethods = $this->validateValues('excludeMethods', $excludeMethods);
        $this->includePaths = $this->validateValues('includePaths', $includePaths);
        $this->excludePaths = $this->validateValues('excludePaths', $excludePaths);
        $this->includeIds = $this->validateValues('includeIds', $includeIds);
        $this->includeExactIds = $this->validateValues('includeExactIds', $includeExactIds);
    }

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

    public function acceptsId(string $renderedId): bool
    {
        if ($this->includeIds === [] && $this->includeExactIds === []) {
            return true;
        }

        if (\in_array($renderedId, $this->includeExactIds, true)) {
            return true;
        }

        return \array_any($this->includeIds, static fn(string $pattern): bool => Wildcard::matches($renderedId, $pattern, caseInsensitive: true));
    }

    /**
     * @param list<string> $groups
     * @param list<non-empty-string> $filters
     */
    private function anyGroupMatches(array $groups, array $filters): bool
    {
        return \array_any($filters, static fn(string $filter): bool => \in_array($filter, $groups, true));
    }

    /**
     * @param list<non-empty-string> $filters
     */
    private function anyNameMatches(string $name, array $filters): bool
    {
        return \array_any($filters, static fn(string $filter): bool => Wildcard::matches($name, $filter, caseInsensitive: false));
    }

    /**
     * @param list<non-empty-string> $prefixes
     */
    private function anyPrefixMatches(string $path, array $prefixes): bool
    {
        return \array_any($prefixes, static fn(string $prefix): bool => \str_starts_with($path, $prefix));
    }

    /**
     * @param non-empty-string $dimension
     * @param list<string> $values
     *
     * @return list<non-empty-string>
     */
    private function validateValues(string $dimension, array $values): array
    {
        $validated = [];

        foreach ($values as $value) {
            if ($value === '') {
                throw new \InvalidArgumentException(\sprintf(
                    'Filter "%s" MUST contain only non-empty strings.',
                    $dimension,
                ));
            }

            $validated[] = $value;
        }

        return $validated;
    }
}
