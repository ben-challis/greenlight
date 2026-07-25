<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Fluent builder handed to coverage configurators.
 *
 * include() stores what to cover, driver() records which driver to prefer,
 * and export() adds a report to write.
 *
 * Config files type-hint this class, so it is part of the public
 * configuration surface.
 */
final class CoverageBuilder
{
    /**
     * @var list<non-empty-string>
     */
    private array $includePaths = [];

    /**
     * @var non-empty-string|null
     */
    private ?string $driver = null;

    /**
     * @var list<CoverageExport>
     */
    private array $exports = [];

    /**
     * @var non-empty-string|null
     */
    private ?string $perTestTarget = null;

    /**
     * @throws InvalidConfiguration
     */
    public function include(string ...$paths): self
    {
        foreach ($paths as $path) {
            if ($path === '') {
                throw new InvalidConfiguration('Coverage include paths cannot be empty.');
            }

            $this->includePaths[] = $path;
        }

        return $this;
    }

    /**
     * @throws InvalidConfiguration
     */
    public function driver(string $driver): self
    {
        if ($driver === '') {
            throw new InvalidConfiguration('Coverage driver cannot be empty.');
        }

        $this->driver = $driver;

        return $this;
    }

    /**
     * @throws InvalidConfiguration
     */
    public function export(string $format, string $target): self
    {
        if ($format === '' || $target === '') {
            throw new InvalidConfiguration('Coverage exports need a non-empty format and target.');
        }

        $this->exports[] = new CoverageExport($format, $target);

        return $this;
    }

    /**
     * Writes the versioned per-test line-coverage map used by impact-aware
     * tooling such as mutation-test adapters.
     *
     * @throws InvalidConfiguration
     */
    public function perTest(string $target): self
    {
        if ($target === '') {
            throw new InvalidConfiguration('Per-test coverage needs a non-empty target.');
        }

        $this->perTestTarget = $target;

        return $this;
    }

    /**
     * @internal
     */
    public function toConfiguration(): CoverageConfiguration
    {
        return new CoverageConfiguration($this->includePaths, $this->driver, $this->exports, $this->perTestTarget);
    }
}
