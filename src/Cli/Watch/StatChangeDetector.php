<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Plugin\WatchSource;

/**
 * A portable file change detector.
 *
 * poll() records the modification time, size, and content hash for each
 * selected file. It reports changed, new, and removed paths.
 *
 * The first poll creates the initial record and reports no changes.
 *
 * @internal
 */
final class StatChangeDetector implements WatchSource
{
    /** @var array<string, string>|null path to fingerprint */
    private ?array $snapshot = null;

    private readonly WatchPathMatcher $matcher;

    /**
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $additionalPaths
     * @param positive-int $maximumFiles
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $additionalPaths = [],
        ?WatchPathMatcher $matcher = null,
        private readonly int $maximumFiles = 100_000,
    ) {
        $this->matcher = $matcher ?? new WatchPathMatcher('', [], []);
    }

    #[\Override]
    public function poll(): array
    {
        $current = $this->scan();

        if ($this->snapshot === null) {
            $this->snapshot = $current;

            return [];
        }

        $changed = [];

        foreach ($current as $path => $fingerprint) {
            if (($this->snapshot[$path] ?? null) !== $fingerprint) {
                $changed[] = $path;
            }
        }

        foreach (\array_keys($this->snapshot) as $path) {
            if (!isset($current[$path])) {
                $changed[] = $path;
            }
        }

        $this->snapshot = $current;

        /** @var list<non-empty-string> $changed */
        return $changed;
    }

    /** @return array<string, string> */
    private function scan(): array
    {
        /** @var array<non-empty-string, true> $candidates */
        $candidates = [];

        foreach ($this->directories as $directory) {
            $this->collectDirectory($directory, false, $candidates);
        }

        foreach ($this->additionalPaths as $path) {
            if ($this->isDirectory($path)) {
                $this->collectDirectory($path, true, $candidates);
            } else {
                $this->collectFile($path, true, true, $candidates);
            }
        }

        \ksort($candidates, \SORT_STRING);
        $snapshot = [];

        foreach (\array_keys($candidates) as $path) {
            $fingerprint = $this->fingerprint($path);

            if ($fingerprint !== null) {
                $snapshot[$path] = $fingerprint;
            }
        }

        return $snapshot;
    }

    /** @param array<non-empty-string, true> $candidates */
    private function collectDirectory(string $directory, bool $additional, array &$candidates): void
    {
        if ($directory === '' || $this->isLink($directory) || !$this->isDirectory($directory)) {
            return;
        }

        /** @var list<non-empty-string> $pending */
        $pending = [$directory];

        while (($current = \array_pop($pending)) !== null) {
            if ($this->matcher->excludesDirectory($current)) {
                continue;
            }

            $entries = ErrorTrap::run(static fn() => \scandir($current));

            if (!\is_array($entries)) {
                continue;
            }

            /** @var list<non-empty-string> $childDirectories */
            $childDirectories = [];

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $path = \rtrim($current, '/') . '/' . $entry;

                if ($this->isLink($path)) {
                    continue;
                }

                if ($this->isDirectory($path)) {
                    $childDirectories[] = $path;

                    continue;
                }

                $this->collectFile($path, $additional, false, $candidates);
            }

            for ($offset = \count($childDirectories) - 1; $offset >= 0; --$offset) {
                $pending[] = $childDirectories[$offset];
            }
        }
    }

    /** @param array<non-empty-string, true> $candidates */
    private function collectFile(string $path, bool $additional, bool $explicit, array &$candidates): void
    {
        if ($path === '' || $this->isLink($path) || !$this->isFile($path)) {
            return;
        }

        $included = $additional
            ? $this->matcher->includesAdditionalFile($path, $explicit)
            : $this->matcher->includesDefaultPhpFile($path);

        if (!$included || isset($candidates[$path])) {
            return;
        }

        $candidates[$path] = true;

        if (\count($candidates) > $this->maximumFiles) {
            throw WatchScanFailed::fileLimitExceeded($this->maximumFiles);
        }
    }

    private function fingerprint(string $path): ?string
    {
        \clearstatcache(true, $path);

        if ($this->isLink($path) || !$this->isFile($path)) {
            return null;
        }

        return ErrorTrap::run(static function () use ($path) {
            $mtime = \filemtime($path);
            $size = \filesize($path);
            $contentHash = \sha1_file($path);

            if (!\is_int($mtime) || !\is_int($size) || !\is_string($contentHash)) {
                return null;
            }

            return $mtime . ':' . $size . ':' . $contentHash;
        });
    }

    private function isLink(string $path): bool
    {
        return ErrorTrap::run(static fn(): bool => \is_link($path));
    }

    private function isDirectory(string $path): bool
    {
        return ErrorTrap::run(static fn(): bool => \is_dir($path));
    }

    private function isFile(string $path): bool
    {
        return ErrorTrap::run(static fn(): bool => \is_file($path));
    }
}
