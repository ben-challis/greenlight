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

    /**
     * @var list<CoverageExport>
     */
    private array $exports = [];

    /**
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
     * @throws InvalidConfiguration
     */
    public function driver(string $driver): self
    {
        if (!\in_array($driver, ['pcov', 'xdebug'], true)) {
            throw new InvalidConfiguration(\sprintf(
                'Unknown coverage driver "%s". Use "pcov" or "xdebug".',
                $driver,
            ));
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
