<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * Collects directory configuration for Greenlight-owned storage.
 * Relative paths use the command working directory, including explicit area directories.
 * Without a root or an area override, Greenlight uses the system temporary directory.
 */
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
     * Sets the parent for the `state`, `cache`, `generated-code`, and `temporary` directories.
     * An explicit area directory overrides its default below this root.
     *
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function rootDirectory(string $directory): self
    {
        $this->rootDirectory = $this->validate($directory, 'Storage root directory');

        return $this;
    }

    /**
     * Sets the directory for saved failures and test-class durations.
     *
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function stateDirectory(string $directory): self
    {
        $this->stateDirectory = $this->validate($directory, 'State directory');

        return $this;
    }

    /**
     * Sets the directory for the discovery cache.
     *
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function cacheDirectory(string $directory): self
    {
        $this->cacheDirectory = $this->validate($directory, 'Cache directory');

        return $this;
    }

    /**
     * Sets the directory for generated double proxy classes.
     *
     * @param non-empty-string $directory
     * @throws InvalidConfiguration
     */
    public function generatedCodeDirectory(string $directory): self
    {
        $this->generatedCodeDirectory = $this->validate($directory, 'Generated-code directory');

        return $this;
    }

    /**
     * Sets the directory for temporary run data, sockets, and attachment staging.
     *
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
            throw InvalidConfiguration::emptyStoragePath($name);
        }

        if (\str_contains($directory, "\0")) {
            throw InvalidConfiguration::storagePathContainsNullByte($name);
        }

        return $directory;
    }
}
