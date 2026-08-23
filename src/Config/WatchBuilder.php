<?php

declare(strict_types=1);

namespace Greenlight\Config;

final class WatchBuilder
{
    private const int DEFAULT_MAXIMUM_FILES = 100_000;

    /**
     * @var positive-int
     */
    private int $debounceMilliseconds = 200;

    /** @var list<non-empty-string> */
    private array $paths = [];

    /** @var list<non-empty-string> */
    private array $includePatterns = [];

    /** @var list<non-empty-string> */
    private array $excludePatterns = [];

    /** @var positive-int */
    private int $maximumFiles = self::DEFAULT_MAXIMUM_FILES;

    /**
     * Sets the quiet period before a new run. The period restarts after each
     * change. Thus, multiple consecutive saves cause one run.
     *
     * @param positive-int $milliseconds
     *
     * @throws InvalidConfiguration
     */
    public function debounceMilliseconds(int $milliseconds): self
    {
        if ($milliseconds < 1) {
            throw new InvalidConfiguration(\sprintf('The watch debounce must be at least 1 millisecond, got %d.', $milliseconds));
        }

        $this->debounceMilliseconds = $milliseconds;

        return $this;
    }

    /**
     * Adds file or directory inputs. Relative paths use the command working
     * directory. Multiple calls add paths.
     *
     * @throws InvalidConfiguration
     */
    public function paths(string ...$paths): self
    {
        $this->paths = [...$this->paths, ...$this->validate(\array_values($paths), 'Watch paths')];

        return $this;
    }

    /**
     * Selects files below additional directory inputs. Multiple calls add
     * patterns. Exact file inputs do not require an include pattern.
     *
     * @throws InvalidConfiguration
     */
    public function include(string ...$patterns): self
    {
        $this->includePatterns = [...$this->includePatterns, ...$this->validate(\array_values($patterns), 'Watch include patterns')];

        return $this;
    }

    /**
     * Removes from all watch inputs each file that matches. Exclusion has
     * precedence over an explicit path or include pattern. Multiple calls add
     * patterns.
     *
     * @throws InvalidConfiguration
     */
    public function exclude(string ...$patterns): self
    {
        $this->excludePatterns = [...$this->excludePatterns, ...$this->validate(\array_values($patterns), 'Watch exclude patterns')];

        return $this;
    }

    /**
     * Sets the maximum number of files that one poll can track.
     *
     * @throws InvalidConfiguration
     */
    public function maximumFiles(int $maximumFiles): self
    {
        if ($maximumFiles < 1) {
            throw new InvalidConfiguration(\sprintf('The watch file limit must be at least 1, got %d.', $maximumFiles));
        }

        $this->maximumFiles = $maximumFiles;

        return $this;
    }

    /**
     * @internal
     * @throws InvalidConfiguration
     */
    public function toConfiguration(): WatchConfiguration
    {
        return new WatchConfiguration(
            $this->debounceMilliseconds,
            $this->paths,
            $this->includePatterns,
            $this->excludePatterns,
            $this->maximumFiles,
        );
    }

    /**
     * @param list<string> $values
     * @return list<non-empty-string>
     * @throws InvalidConfiguration
     */
    private function validate(array $values, string $name): array
    {
        foreach ($values as $value) {
            if ($value === '') {
                throw new InvalidConfiguration($name . ' cannot contain an empty string.');
            }

            if (\str_contains($value, "\0")) {
                throw new InvalidConfiguration($name . ' cannot contain a null byte.');
            }
        }

        return $values;
    }
}
