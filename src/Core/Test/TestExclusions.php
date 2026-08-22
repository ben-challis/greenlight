<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * Defines the exclusive dimensions of a test selection.
 *
 * @internal
 */
final readonly class TestExclusions
{
    /** @var list<non-empty-string> */
    public array $groups;

    /** @var list<non-empty-string> */
    public array $classes;

    /** @var list<non-empty-string> */
    public array $methods;

    /** @var list<non-empty-string> */
    public array $paths;

    /**
     * @param list<string> $groups
     * @param list<string> $classes
     * @param list<string> $methods
     * @param list<string> $paths
     */
    public function __construct(
        array $groups = [],
        array $classes = [],
        array $methods = [],
        array $paths = [],
    ) {
        $this->groups = $this->validateValues('groups', $groups);
        $this->classes = $this->validateValues('classes', $classes);
        $this->methods = $this->validateValues('methods', $methods);
        $this->paths = $this->validateValues('paths', $paths);
    }

    /** @param list<non-empty-string> $paths */
    public function withPaths(array $paths): self
    {
        return new self($this->groups, $this->classes, $this->methods, $paths);
    }

    /**
     * @param list<string> $values
     *
     * @return list<non-empty-string>
     */
    private function validateValues(string $name, array $values): array
    {
        $validated = [];

        foreach ($values as $value) {
            $validated[] = $this->validateValue($name, $value);
        }

        return $validated;
    }

    /** @return non-empty-string */
    private function validateValue(string $name, string $value): string
    {
        if ($value === '') {
            throw new \InvalidArgumentException(\sprintf('%s cannot contain an empty string.', $name));
        }

        return $value;
    }
}
