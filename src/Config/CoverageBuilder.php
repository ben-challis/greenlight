<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** Configures coverage collection and exports. */
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

    private ?float $minimumPercentage = null;

    /** @var int<0, max>|null */
    private ?int $maximumUncoveredLines = null;

    private bool $requireDriver = false;

    /**
     * @var list<CoverageExport>
     */
    private array $exports = [];

    /**
     * @param non-empty-string ...$paths
     *
     * @throws InvalidConfiguration
     */
    public function include(string ...$paths): self
    {
        $validated = [];

        foreach ($paths as $path) {
            if ($path === '') {
                throw new InvalidConfiguration('Coverage include paths cannot be empty.');
            }

            if (\str_contains($path, "\0")) {
                throw new InvalidConfiguration('Coverage include paths cannot contain a null byte.');
            }

            $validated[] = $path;
        }

        $this->includePaths = [...$this->includePaths, ...$validated];

        return $this;
    }

    /**
     * @param non-empty-string $driver
     *
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
     * Sets the minimum accepted total line-coverage percentage.
     *
     * @param float $percentage A value from 0 through 100 with at most two decimal places.
     *
     * @throws InvalidConfiguration
     */
    public function minimumPercentage(float $percentage): self
    {
        if (!\is_finite($percentage) || $percentage < 0.0 || $percentage > 100.0) {
            throw new InvalidConfiguration('Minimum coverage percentage must be from 0 through 100.');
        }

        if (\round($percentage, 2) !== $percentage) {
            throw new InvalidConfiguration('Minimum coverage percentage can have at most two decimal places.');
        }

        $this->minimumPercentage = $percentage;

        return $this;
    }

    /**
     * Sets the maximum accepted number of uncovered executable lines.
     *
     * @param int<0, max> $lines
     * @throws InvalidConfiguration
     */
    public function maximumUncoveredLines(int $lines): self
    {
        if ($lines < 0) {
            throw new InvalidConfiguration('Maximum uncovered lines cannot be negative.');
        }

        $this->maximumUncoveredLines = $lines;

        return $this;
    }

    /** Fails the run when the selected coverage driver is not available. */
    public function requireDriver(bool $required = true): self
    {
        $this->requireDriver = $required;

        return $this;
    }

    /**
     * @param 'json'|'lcov'|'clover'|'cobertura'|'html' $format
     * @param non-empty-string $target
     *
     * @throws InvalidConfiguration
     */
    public function export(string $format, string $target): self
    {
        if ($format === '') {
            throw new InvalidConfiguration('Coverage exports need a non-empty format and target.');
        }

        if ($target === '') {
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
        return new CoverageConfiguration(
            $this->includePaths,
            $this->driver,
            $this->exports,
            $this->minimumPercentage,
            $this->maximumUncoveredLines,
            $this->requireDriver,
        );
    }
}
