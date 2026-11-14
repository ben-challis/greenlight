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
 * Content hashes are expensive for a large tree. A poll can reuse the previous
 * hash when the modification time, size, change time, and inode are the same.
 * Both times must also be older than the recent-write window. An editor can write
 * twice in one second with the same size, and a tool can set an old
 * modification time again. Inside the window, the times cannot show the
 * second write, so the hash is read again.
 *
 * The first poll creates the initial record and reports no changes.
 *
 * @internal
 */
final class StatChangeDetector implements WatchSource
{
    private const float RECENT_WRITE_SECONDS = 2.0;

    /** @var array<string, string>|null path to fingerprint */
    private ?array $snapshot = null;

    /** @var array<string, array{string, string}> path to stat key and content hash */
    private array $hashes = [];

    private readonly WatchPathMatcher $matcher;

    /** @var \Closure(): float */
    private readonly \Closure $now;

    /** @var \Closure(string): (string|false) */
    private readonly \Closure $hashFile;

    /**
     * @param list<non-empty-string> $directories
     * @param list<non-empty-string> $additionalPaths
     * @param positive-int $maximumFiles
     * @param (\Closure(): float)|null $now
     * @param (\Closure(string): (string|false))|null $hashFile
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $additionalPaths = [],
        ?WatchPathMatcher $matcher = null,
        private readonly int $maximumFiles = 100_000,
        ?\Closure $now = null,
        ?\Closure $hashFile = null,
    ) {
        $this->matcher = $matcher ?? new WatchPathMatcher('', [], []);
        $this->now = $now ?? static fn(): float => \microtime(true);
        $this->hashFile = $hashFile ?? \sha1_file(...);
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
        $hashes = [];
        $now = ($this->now)();

        foreach (\array_keys($candidates) as $path) {
            $fingerprint = $this->fingerprint($path, $now, $hashes);

            if ($fingerprint !== null) {
                $snapshot[$path] = $fingerprint;
            }
        }

        $this->hashes = $hashes;

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

    /** @param array<string, array{string, string}> $hashes */
    private function fingerprint(string $path, float $now, array &$hashes): ?string
    {
        \clearstatcache(true, $path);

        if ($this->isLink($path) || !$this->isFile($path)) {
            return null;
        }

        $stat = ErrorTrap::run(static fn() => \stat($path));

        if (!\is_array($stat)) {
            return null;
        }

        $mtime = $stat['mtime'];
        $size = $stat['size'];
        $statKey = $mtime . ':' . $size . ':' . $stat['ctime'] . ':' . $stat['ino'];
        $previous = $this->hashes[$path] ?? null;

        $lastWrite = \max($mtime, $stat['ctime']);

        if ($previous !== null && $previous[0] === $statKey && $now - $lastWrite >= self::RECENT_WRITE_SECONDS) {
            $contentHash = $previous[1];
        } else {
            $contentHash = ErrorTrap::run(fn() => ($this->hashFile)($path));

            if (!\is_string($contentHash)) {
                return null;
            }
        }

        $hashes[$path] = [$statKey, $contentHash];

        return $mtime . ':' . $size . ':' . $contentHash;
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
