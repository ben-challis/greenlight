<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;

/**
 * Persists the previous run's failure set and per-class durations between
 * invocations.
 *
 * record() writes both after a run. failedTests() feeds --failed re-runs and
 * lets plain runs order failed classes first; classSeconds() lets the
 * scheduler order the rest longest first.
 *
 * The file lives under the system temp dir keyed by a hash of the working
 * directory, the same convention as the proxy cache, so the project tree
 * stays untouched and a lost file costs one full run plus one unpacked
 * schedule.
 *
 * @internal
 */
final readonly class RunState
{
    private function __construct(private string $file) {}

    public static function forWorkingDirectory(string $workingDirectory): self
    {
        return new self(\sprintf(
            '%s/greenlight-state-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($workingDirectory), 0, 12),
        ));
    }

    /**
     * The failed test ids of the previous run, or null when no usable state
     * exists (never ran, or the file is unreadable or corrupt). An empty
     * list is real state: the previous run passed everything.
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
     * Recorded class durations from the previous run, advisory only: a
     * missing or corrupt file reads as no data.
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
            if (\is_string($class) && $class !== '' && (\is_float($seconds) || \is_int($seconds))) {
                $durations[$class] = (float) $seconds;
            }
        }

        return $durations;
    }

    /**
     * Returns false when the state could not be persisted, so the caller can
     * surface the loss instead of letting the next run start cold silently.
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
