<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Fluent builder handed to coverage configurators. Stores what to include,
 * which driver to prefer, and which reports to export. Config files
 * type-hint this class, so it is part of the public configuration surface.
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
     * @internal
     */
    public function toConfiguration(): CoverageConfiguration
    {
        return new CoverageConfiguration($this->includePaths, $this->driver, $this->exports);
    }
}
