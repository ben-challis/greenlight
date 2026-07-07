<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Include-directory filter applied when raw driver output is normalised
 * into a CoverageMap.
 *
 * accepts() passes a file when it sits under any of the configured
 * directories; an empty directory list accepts every file.
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
                throw new \InvalidArgumentException('Coverage include directories must be non-empty paths.');
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
