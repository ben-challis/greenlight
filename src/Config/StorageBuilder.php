<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** Collects directory configuration for Greenlight-owned storage. */
final class StorageBuilder
{
    /** @var non-empty-string|null */
    private ?string $rootDirectory = null;

    /** @var non-empty-string|null */
    private ?string $stateDirectory = null;

    /** @var non-empty-string|null */
    private ?string $cacheDirectory = null;

    /** @var non-empty-string|null */
    private ?string $generatedCodeDirectory = null;

    /** @var non-empty-string|null */
    private ?string $temporaryDirectory = null;

    /**
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function rootDirectory(string $directory): self
    {
        $this->rootDirectory = $this->validate($directory, 'Storage root directory');

        return $this;
    }

    /**
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function stateDirectory(string $directory): self
    {
        $this->stateDirectory = $this->validate($directory, 'State directory');

        return $this;
    }

    /**
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function cacheDirectory(string $directory): self
    {
        $this->cacheDirectory = $this->validate($directory, 'Cache directory');

        return $this;
    }

    /**
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function generatedCodeDirectory(string $directory): self
    {
        $this->generatedCodeDirectory = $this->validate($directory, 'Generated-code directory');

        return $this;
    }

    /**
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function temporaryDirectory(string $directory): self
    {
        $this->temporaryDirectory = $this->validate($directory, 'Temporary directory');

        return $this;
    }

    /** @internal */
    public function toConfiguration(): StorageConfiguration
    {
        return new StorageConfiguration(
            $this->rootDirectory,
            $this->stateDirectory,
            $this->cacheDirectory,
            $this->generatedCodeDirectory,
            $this->temporaryDirectory,
        );
    }

    /**
     * @return non-empty-string
     * @throws InvalidConfiguration
     */
    private function validate(string $directory, string $name): string
    {
        if ($directory === '') {
            throw new InvalidConfiguration($name . ' cannot be empty.');
        }

        if (\str_contains($directory, "\0")) {
            throw new InvalidConfiguration($name . ' cannot contain a null byte.');
        }

        return $directory;
    }
}
