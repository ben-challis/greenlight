<?php

declare(strict_types=1);

namespace Greenlight\Config;

/** @internal */
final readonly class WatchConfiguration
{
    /**
     * @var positive-int
     */
    public int $debounceMilliseconds;

    /** @var list<non-empty-string> */
    public array $paths;

    /** @var list<non-empty-string> */
    public array $includePatterns;

    /** @var list<non-empty-string> */
    public array $excludePatterns;

    /** @var positive-int */
    public int $maximumFiles;

    /**
     * @param array<mixed> $paths
     * @param array<mixed> $includePatterns
     * @param array<mixed> $excludePatterns
     *
     * @throws InvalidConfiguration
     */
    public function __construct(
        int $debounceMilliseconds = 200,
        array $paths = [],
        array $includePatterns = [],
        array $excludePatterns = [],
        int $maximumFiles = 100_000,
    ) {
        if ($debounceMilliseconds < 1) {
            throw InvalidConfiguration::invalidWatchDebounce($debounceMilliseconds);
        }

        if ($maximumFiles < 1) {
            throw InvalidConfiguration::invalidWatchFileLimit($maximumFiles);
        }

        $this->debounceMilliseconds = $debounceMilliseconds;
        $this->paths = $this->validate($paths, 'Watch paths');
        $this->includePatterns = $this->validate($includePatterns, 'Watch include patterns');
        $this->excludePatterns = $this->validate($excludePatterns, 'Watch exclude patterns');
        $this->maximumFiles = $maximumFiles;
    }

    /**
     * @param array<mixed> $values
     * @return list<non-empty-string>
     * @throws InvalidConfiguration
     */
    private function validate(array $values, string $name): array
    {
        if (!\array_is_list($values)) {
            throw InvalidConfiguration::watchPathsNotAList($name);
        }

        $validated = [];
        foreach ($values as $value) {
            if (!\is_string($value) || $value === '') {
                throw InvalidConfiguration::watchPathNotANonEmptyString($name);
            }

            if (\str_contains($value, "\0")) {
                throw InvalidConfiguration::watchPathContainsNullByte($name);
            }

            $validated[] = $value;
        }

        return $validated;
    }
}
