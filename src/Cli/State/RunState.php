<?php

declare(strict_types=1);

namespace Greenlight\Cli\State;

use Greenlight\Internal\Filesystem\AtomicFile;
use Greenlight\Internal\Filesystem\AtomicFileError;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Stores failed test IDs and class durations in a selected file.
 * forWorkingDirectory() selects a project-specific file in the system temporary
 * directory. CLI runs use forFile() with the resolved storage configuration.
 *
 * @internal
 */
final class RunState
{
    private bool $loaded = false;

    /**
     * @var array<mixed>|null
     */
    private ?array $snapshot = null;

    private function __construct(private readonly string $file) {}

    public static function forFile(string $file): self
    {
        return new self($file);
    }

    public static function forWorkingDirectory(string $workingDirectory): self
    {
        return self::forFile(\sprintf(
            '%s/greenlight-state-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($workingDirectory), 0, 12),
        ));
    }

    /**
     * Returns failed test IDs from the previous run.
     *
     * Returns null if no usable state exists. This occurs before the first run
     * or when the file is unreadable or corrupt. An empty list means that no
     * test failed or errored in the recorded run.
     *
     * @return list<non-empty-string>|null
     */
    public function failedTests(): ?array
    {
        $decoded = $this->decoded();
        $failed = $decoded['failed'] ?? null;

        if (!\is_array($failed) || !\array_is_list($failed)) {
            return null;
        }

        $ids = [];

        foreach ($failed as $id) {
            if (\is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * @return array<mixed>|null
     */
    private function decoded(): ?array
    {
        if ($this->loaded) {
            return $this->snapshot;
        }

        $this->loaded = true;
        $file = $this->file;
        $exists = ErrorTrap::run(static fn() => \is_file($file));

        if (!$exists) {
            return null;
        }

        $raw = ErrorTrap::run(static fn() => \file_get_contents($file));

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $decoded = \json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return $this->snapshot = \is_array($decoded) ? $decoded : null;
    }

    /**
     * Returns recorded class durations from the previous run.
     *
     * This data is advisory. A missing or corrupt file gives no data.
     *
     * @return array<non-empty-string, float>
     */
    public function classSeconds(): array
    {
        $decoded = $this->decoded();

        if ($decoded === null || !\is_array($decoded['classSeconds'] ?? null)) {
            return [];
        }

        $durations = [];

        foreach ($decoded['classSeconds'] as $class => $seconds) {
            if (!\is_string($class) || $class === '' || (!\is_float($seconds) && !\is_int($seconds))) {
                continue;
            }

            $seconds = (float) $seconds;

            if (!\is_finite($seconds) || $seconds < 0.0) {
                continue;
            }

            $durations[$class] = $seconds;
        }

        return $durations;
    }

    /**
     * Returns false when Greenlight cannot store the state.
     *
     * @param list<non-empty-string> $failedTests
     * @param array<non-empty-string, float> $classSeconds
     */
    public function record(array $failedTests, array $classSeconds = []): bool
    {
        try {
            $encoded = \json_encode(
                ['failed' => $failedTests, 'classSeconds' => $classSeconds],
                \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
            );
        } catch (\JsonException) {
            return false;
        }

        $directory = \dirname($this->file);
        $directoryExists = ErrorTrap::run(static fn() => \is_dir($directory));

        if (!$directoryExists && !ErrorTrap::run(static fn() => \mkdir($directory, 0o700, true))) {
            $directoryExists = ErrorTrap::run(static fn() => \is_dir($directory));

            if (!$directoryExists) {
                return false;
            }
        }

        try {
            AtomicFile::write($this->file, $encoded);
        } catch (AtomicFileError) {
            return false;
        }

        $this->loaded = true;
        $this->snapshot = ['failed' => $failedTests, 'classSeconds' => $classSeconds];

        return true;
    }
}
