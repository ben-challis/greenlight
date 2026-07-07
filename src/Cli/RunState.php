<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Persists the previous run's failure set between invocations, so --failed
 * can re-run it and plain runs can order failed classes first. The file
 * lives under the system temp dir keyed by a hash of the working directory,
 * the same convention as the proxy cache, so the project tree stays
 * untouched and a lost file costs one full run.
 *
 * @internal
 */
final readonly class RunState
{
    private function __construct(
        private string $file,
    ) {}

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
        if (!\is_file($this->file)) {
            return null;
        }

        $raw = @\file_get_contents($this->file);

        if (!\is_string($raw)) {
            return null;
        }

        try {
            $decoded = \json_decode($raw, true, 8, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (!\is_array($decoded) || !\is_array($decoded['failed'] ?? null)) {
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
     * @param list<non-empty-string> $failedTests
     */
    public function record(array $failedTests): void
    {
        @\file_put_contents($this->file, \json_encode(
            ['failed' => $failedTests],
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES,
        ));
    }
}
