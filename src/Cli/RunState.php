<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;

/**
 * Stores failures and class durations in the system temporary directory.
 * The project directory identifies the state.
 *
 * @internal
 */
final readonly class RunState
{
    public function __construct(private string $file) {}

    public static function forWorkingDirectory(string $workingDirectory): self
    {
        return new self(\sprintf(
            '%s/greenlight-state-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($workingDirectory), 0, 12),
        ));
    }

    /**
     * Returns failed test IDs from the previous run.
     *
     * Returns null if no usable state exists. This occurs before the first run
     * or when the file is unreadable or corrupt. An empty list means that all
     * tests passed in the previous run.
     *
     * @return list<non-empty-string>|null
     */
    public function failedTests(): ?array
    {
        $decoded = $this->decoded();

        if ($decoded === null || !\is_array($decoded['failed'] ?? null)) {
            return null;
        }

        $ids = [];

        foreach ($decoded['failed'] as $id) {
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
        if (!\is_file($this->file)) {
            return null;
        }

        $file = $this->file;
        $raw = ErrorTrap::run(static fn(): string|false => \file_get_contents($file));

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $decoded = \json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
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

        try {
            AtomicFile::write($this->file, $encoded);
        } catch (AtomicFileError) {
            return false;
        }

        return true;
    }
}
