<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Collects coverage maps into one map.
 *
 * The add() method merges only the files of the incoming map. Thus, the cost
 * of each call depends on the incoming map and not on the collected map.
 * The build() method sorts the files one time. It keeps the result until the
 * next add() call.
 *
 * @internal
 */
final class CoverageMapBuilder
{
    /**
     * @var array<non-empty-string, FileCoverage>
     */
    private array $files = [];

    private ?CoverageMap $built = null;

    public function add(CoverageMap $map): void
    {
        if ($map->isEmpty()) {
            return;
        }

        $this->built = null;

        foreach ($map->files() as $path => $file) {
            $existing = $this->files[$path] ?? null;
            $this->files[$path] = $existing?->merge($file) ?? $file;
        }
    }

    public function build(): CoverageMap
    {
        return $this->built ??= new CoverageMap(\array_values($this->files));
    }
}
