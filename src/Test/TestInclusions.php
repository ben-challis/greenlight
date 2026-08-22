<?php

declare(strict_types=1);

namespace Greenlight\Test;

/**
 * Defines the inclusive dimensions of a test selection.
 *
 * @internal
 */
final readonly class TestInclusions
{
    /** @var list<non-empty-string> */
    public array $groups;

    /** @var list<non-empty-string> */
    public array $classes;

    /** @var list<non-empty-string> */
    public array $methods;

    /** @var list<non-empty-string> */
    public array $paths;

    /** @var list<non-empty-string> */
    public array $idPatterns;

    /** @var list<non-empty-string> */
    public array $exactIds;

    /**
     * @param list<string> $groups
     * @param list<string> $classes
     * @param list<string> $methods
     * @param list<string> $paths
     * @param list<string> $idPatterns
     * @param list<string> $exactIds
     */
    public function __construct(
        array $groups = [],
        array $classes = [],
        array $methods = [],
        array $paths = [],
        array $idPatterns = [],
        array $exactIds = [],
    ) {
        $this->groups = $this->validateValues('groups', $groups);
        $this->classes = $this->validateValues('classes', $classes);
        $this->methods = $this->validateValues('methods', $methods);
        $this->paths = $this->validateValues('paths', $paths);
        $this->idPatterns = $this->validateValues('idPatterns', $idPatterns);
        $this->exactIds = $this->validateValues('exactIds', $exactIds);
    }

    /** @param list<non-empty-string> $ids */
    public function withExactIds(array $ids): self
    {
        return new self($this->groups, $this->classes, $this->methods, $this->paths, $this->idPatterns, $ids);
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
