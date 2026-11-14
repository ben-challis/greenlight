<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection;

/**
 * Selects included directories when Greenlight converts raw driver output to a CoverageMap.
 *
 * accepts() accepts a file in a configured directory. An empty directory list
 * accepts each file. A file in an excluded directory is never accepted.
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
     * @var list<non-empty-string> directory prefixes, each ending in a slash
     */
    private array $excludedPrefixes;

    /**
     * @param list<string> $includeDirectories
     * @param list<string> $excludeDirectories
     */
    public function __construct(array $includeDirectories = [], array $excludeDirectories = [])
    {
        $this->prefixes = $this->prefixes($includeDirectories, 'include');
        $this->excludedPrefixes = $this->prefixes($excludeDirectories, 'exclude');
    }

    public static function all(): self
    {
        return new self();
    }

    public function accepts(string $file): bool
    {
        if (\array_any($this->excludedPrefixes, static fn(string $prefix): bool => \str_starts_with($file, $prefix))) {
            return false;
        }

        if ($this->prefixes === []) {
            return true;
        }

        return \array_any($this->prefixes, static fn(string $prefix): bool => \str_starts_with($file, $prefix));
    }

    /**
     * @param list<string> $directories
     *
     * @return list<non-empty-string>
     */
    private function prefixes(array $directories, string $kind): array
    {
        $prefixes = [];

        foreach ($directories as $directory) {
            if ($directory === '') {
                throw new \InvalidArgumentException(\sprintf('Use nonempty paths for coverage %s directories.', $kind));
            }

            $prefixes[] = \rtrim($directory, '/') . '/';
        }

        return $prefixes;
    }
}
