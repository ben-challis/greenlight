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
    private bool $windows;

    /**
     * @var list<non-empty-string> directory prefixes, each ending in a slash
     */
    private array $prefixes;

    /**
     * @param list<string> $includeDirectories
     */
    public function __construct(
        array $includeDirectories = [],
        string $directorySeparator = \DIRECTORY_SEPARATOR,
    ) {
        $this->windows = $directorySeparator === '\\';
        $prefixes = [];

        foreach ($includeDirectories as $directory) {
            if ($directory === '') {
                throw new \InvalidArgumentException('Use nonempty paths for coverage include directories.');
            }

            $trimmed = \rtrim($this->normalize($directory), '/');
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

        $normalized = $this->normalize($file);

        return \array_any($this->prefixes, static fn(string $prefix): bool => \str_starts_with($normalized, $prefix));
    }

    private function normalize(string $path): string
    {
        return $this->windows ? \str_replace('\\', '/', $path) : $path;
    }
}
