<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * A configuration builder received an invalid value or an invalid combination.
 * Use a named factory to create an error for a specific validation failure.
 * The constructor is private.
 */
final class InvalidConfiguration extends \InvalidArgumentException
{
    private function __construct(string $message, int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }

    public static function emptyArtifactDirectory(): self
    {
        return new self('Artifact directory cannot be empty.');
    }

    public static function artifactDirectoryContainsNullByte(): self
    {
        return new self('Artifact directory cannot contain a null byte.');
    }

    public static function invalidArtifactCountPerTest(): self
    {
        return new self('Artifact count per test must be at least 1.');
    }

    public static function invalidArtifactCountPerRun(): self
    {
        return new self('Artifact count per run must be at least 1.');
    }

    public static function invalidCompletedRunCount(): self
    {
        return new self('Completed artifact run count must be at least 1.');
    }

    public static function invalidCompletedRunAge(): self
    {
        return new self('Completed artifact run age must be at least 1 second.');
    }

    public static function emptyCoveragePath(): self
    {
        return new self('Coverage include paths cannot be empty.');
    }

    public static function coveragePathContainsNullByte(): self
    {
        return new self('Coverage include paths cannot contain a null byte.');
    }

    public static function emptyCoverageDriver(): self
    {
        return new self('Coverage driver cannot be empty.');
    }

    public static function coveragePercentageOutOfRange(): self
    {
        return new self('Minimum coverage percentage must be from 0 through 100.');
    }

    public static function coveragePercentageTooPrecise(): self
    {
        return new self('Minimum coverage percentage can have at most two decimal places.');
    }

    public static function negativeUncoveredLineLimit(): self
    {
        return new self('Maximum uncovered lines cannot be negative.');
    }

    public static function emptyCoverageExport(): self
    {
        return new self('Coverage exports need a non-empty format and target.');
    }

    public static function unknownCoverageFormat(string $format): self
    {
        return new self(
            \sprintf(
                'Unknown coverage export format "%s". Use "json", "lcov", "clover", "cobertura", or "html".',
                $format,
            ),
        );
    }

    public static function coverageTargetContainsNullByte(): self
    {
        return new self('Coverage export target cannot contain a null byte.');
    }

    public static function testPathsNotAList(): self
    {
        return new self('Test paths must be a list.');
    }

    public static function testPathNotAString(): self
    {
        return new self('Test paths must contain only strings.');
    }

    public static function emptyTestPath(): self
    {
        return new self('Test paths cannot be empty strings.');
    }

    public static function testPathContainsNullByte(): self
    {
        return new self('Test paths cannot contain a null byte.');
    }

    public static function missingTestPaths(): self
    {
        return new self('paths() needs at least one directory.');
    }

    public static function emptySuiteName(): self
    {
        return new self('Suite names cannot be empty.');
    }

    public static function duplicateSuite(string $name): self
    {
        return new self(\sprintf('Suite "%s" is declared twice.', $name));
    }

    public static function invalidResourceName(\InvalidArgumentException $previous): self
    {
        return new self($previous->getMessage(), $previous->getCode(), $previous);
    }

    public static function invalidResourceLimit(string $name, int $limit): self
    {
        return new self(\sprintf('Resource "%s" must have a limit of at least 1, got %d.', $name, $limit));
    }

    public static function duplicateResourceLimit(string $name): self
    {
        return new self(\sprintf('Resource limit "%s" is declared twice.', $name));
    }

    public static function emptyDeprecationPattern(): self
    {
        return new self('ignoreDeprecationsMatching() patterns cannot be empty.');
    }

    public static function invalidPluginFactory(\InvalidArgumentException $previous): self
    {
        return new self($previous->getMessage(), $previous->getCode(), $previous);
    }

    public static function negativeRandomSeed(int $seed): self
    {
        return new self(\sprintf('Random order seed must be a nonnegative integer. Actual value: %d.', $seed));
    }

    public static function invalidWorkerCountString(string $count): self
    {
        return new self(\sprintf('Worker count must be a positive integer or "auto", got "%s".', $count));
    }

    public static function invalidMemorySizeSyntax(string $value): self
    {
        return new self(
            \sprintf(
                'Invalid memory size "%s". Use a positive byte count or a K, M, or G suffix, for example "256M".',
                $value,
            ),
        );
    }

    public static function memorySizeOverflow(string $value): self
    {
        return new self(\sprintf('Invalid memory size "%s". The value does not fit in an integer byte count.', $value));
    }

    public static function nonPositiveMemorySize(string $value): self
    {
        return new self(\sprintf('Invalid memory size "%s". The amount must be at least 1.', $value));
    }

    public static function emptyStoragePath(string $name): self
    {
        return new self($name . ' cannot be empty.');
    }

    public static function storagePathContainsNullByte(string $name): self
    {
        return new self($name . ' cannot contain a null byte.');
    }

    public static function emptySuitePath(string $name): self
    {
        return new self(\sprintf('Suite "%s" was given an empty path.', $name));
    }

    public static function suitePathContainsNullByte(string $name): self
    {
        return new self(\sprintf('Suite "%s" paths cannot contain a null byte.', $name));
    }

    public static function emptySuiteTag(string $name): self
    {
        return new self(\sprintf('Suite "%s" was given an empty tag.', $name));
    }

    public static function missingSuitePaths(string $name): self
    {
        return new self(
            \sprintf(
                'Suite "%s" has no paths. Call in() with at least one directory inside its configurator.',
                $name,
            ),
        );
    }

    public static function suitePathsNotAList(string $name): self
    {
        return new self(\sprintf('Suite "%s" paths must be a list.', $name));
    }

    public static function suitePathNotAString(string $name): self
    {
        return new self(\sprintf('Suite "%s" was given a path that is not a string.', $name));
    }

    public static function suiteTagsNotAList(string $name): self
    {
        return new self(\sprintf('Suite "%s" tags must be a list.', $name));
    }

    public static function suiteTagNotAString(string $name): self
    {
        return new self(\sprintf('Suite "%s" was given a tag that is not a string.', $name));
    }

    public static function invalidWatchDebounce(int $milliseconds): self
    {
        return new self(\sprintf('The watch debounce must be at least 1 millisecond, got %d.', $milliseconds));
    }

    public static function invalidWatchFileLimit(int $maximumFiles): self
    {
        return new self(\sprintf('The watch file limit must be at least 1, got %d.', $maximumFiles));
    }

    public static function emptyWatchPath(string $name): self
    {
        return new self($name . ' cannot contain an empty string.');
    }

    public static function watchPathContainsNullByte(string $name): self
    {
        return new self($name . ' cannot contain a null byte.');
    }

    public static function watchPathsNotAList(string $name): self
    {
        return new self($name . ' must be a list.');
    }

    public static function watchPathNotANonEmptyString(string $name): self
    {
        return new self($name . ' must contain non-empty strings.');
    }

    public static function nonPositiveWorkerCount(int $count): self
    {
        return new self(\sprintf('Worker count must be at least 1, got %d.', $count));
    }
}
