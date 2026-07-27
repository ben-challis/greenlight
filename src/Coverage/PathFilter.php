<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Selects included directories when Greenlight converts raw driver output to a CoverageMap.
 *
 * accepts() accepts a file in a configured directory. An empty directory list
 * accepts each file.
 *
 * @internal
 */
final readonly class PathFilter
{
    /**
     * @var list<non-empty-string> directory prefixes, each ending in a slash
     */
    private array $prefixes;

    /**
     * @param list<string> $includeDirectories
     */
    public function __construct(array $includeDirectories = [])
    {
        $prefixes = [];

        foreach ($includeDirectories as $directory) {
            $trimmed = \rtrim($directory, '/');

            if ($trimmed === '') {
                throw new \InvalidArgumentException('Use nonempty paths for coverage include directories.');
            }

            $prefixes[] = $trimmed . '/';
        }

        $this->prefixes = $prefixes;
    }

    public static function all(): self
    {
        return new self();
    }

    public function accepts(string $file): bool
    {
        if ($this->prefixes === []) {
            return true;
        }
        return \array_any($this->prefixes, static fn(string $prefix): bool => \str_starts_with($file, $prefix));
    }
}
