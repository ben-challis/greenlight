<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Plugin\WatchSource;

/**
 * A portable file change detector.
 *
 * poll() reports changed, new, and removed paths. The first poll creates the
 * initial record and reports no changes.
 *
 * A poll does not read file contents in the normal case. It keeps the entry
 * names of each directory and lists a directory again only when its stat data
 * changed. A create, delete, or rename in a directory changes that data. Each
 * known file then gets one lstat() call, and the poll compares its device,
 * inode, size, modification time, and change time.
 *
 * Stat times have one-second precision. A write in the same second as the
 * previous poll can thus leave all stat data the same. An entry with a time
 * that is not older than the start of the previous poll is racy. For a racy
 * file, the poll also compares a content hash. A racy directory is listed
 * again.
 *
 * @internal
 */
final class StatChangeDetector implements WatchSource
{
    private const int TYPE_MASK = 0o170000;

    private const int TYPE_DIRECTORY = 0o040000;

    private const int TYPE_FILE = 0o100000;

    /** @var array<string, array{string, string|null}>|null path to stat key and racy content hash */
    private ?array $files = null;

    /** @var array<string, array{string, list<string>}> directory to stat key and entry names */
    private array $listings = [];

    /** Start of the previous poll, in whole seconds. */
    private int $previousStart = \PHP_INT_MIN;

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
        $start = (int) \floor(($this->now)());

        // The first poll has no earlier poll. Only entries from this second
        // are racy then.
        if ($this->files === null) {
            $this->previousStart = $start;
        }

        $current = $this->scan();
        $previous = $this->files;
        $this->files = $current;
        $this->previousStart = $start;

        if ($previous === null) {
            return [];
        }

        $changed = [];

        foreach ($current as $path => $record) {
            if ($this->differs($previous[$path] ?? null, $record)) {
                $changed[] = $path;
            }
        }

        foreach (\array_keys($previous) as $path) {
            if (!isset($current[$path])) {
                $changed[] = $path;
            }
        }

        /** @var list<non-empty-string> $changed */
        return $changed;
    }

    /** @return array<string, array{string, string|null}> */
    private function scan(): array
    {
        /** @var array<string, array{string, string|null}> $files */
        $files = [];
        $listings = [];
        $visited = [];

        foreach ($this->directories as $directory) {
            $stat = $this->lstat($directory);

            if ($stat !== null && $this->type($stat) === self::TYPE_DIRECTORY) {
                $this->scanDirectory($directory, $stat, false, $files, $listings, $visited);
            }
        }

        foreach ($this->additionalPaths as $path) {
            $stat = $this->lstat($path);

            if ($stat === null) {
                continue;
            }

            if ($this->type($stat) === self::TYPE_DIRECTORY) {
                $this->scanDirectory($path, $stat, true, $files, $listings, $visited);
            } elseif ($this->type($stat) === self::TYPE_FILE && $this->matcher->includesAdditionalFile($path, true)) {
                $this->record($path, $stat, $files);
            }
        }

        $this->listings = $listings;
        \ksort($files, \SORT_STRING);

        return $files;
    }

    /**
     * @param array<array-key, int> $stat
     * @param array<string, array{string, string|null}> $files
     * @param array<string, array{string, list<string>}> $listings
     * @param array<string, true> $visited
     */
    private function scanDirectory(
        string $root,
        array $stat,
        bool $additional,
        array &$files,
        array &$listings,
        array &$visited,
    ): void
    {
        /** @var list<array{string, array<array-key, int>}> $pending */
        $pending = [[$root, $stat]];

        while (($next = \array_pop($pending)) !== null) {
            [$directory, $directoryStat] = $next;

            // A watched root and an additional path can overlap. Each mode
            // applies its own file filter, so each mode visits the directory.
            $visit = $directory . "\0" . ($additional ? 'additional' : 'default');

            if (isset($visited[$visit]) || $this->matcher->excludesDirectory($directory)) {
                continue;
            }

            $visited[$visit] = true;
            $names = $listings[$directory][1] ?? $this->entries($directory, $directoryStat);

            if ($names === null) {
                continue;
            }

            $listings[$directory] = [$this->statKey($directoryStat), $names];
            $prefix = \rtrim($directory, '/') . '/';
            $entries = ErrorTrap::run(static function () use ($names, $prefix): array {
                $stats = [];

                foreach ($names as $name) {
                    $entryStat = \lstat($prefix . $name);

                    if (\is_array($entryStat)) {
                        $stats[$prefix . $name] = $entryStat;
                    }
                }

                return $stats;
            });
            $childDirectories = [];

            foreach ($entries as $path => $entryStat) {
                $type = $this->type($entryStat);

                if ($type === self::TYPE_DIRECTORY) {
                    $childDirectories[] = [$path, $entryStat];
                } elseif ($type === self::TYPE_FILE) {
                    $included = $additional
                        ? $this->matcher->includesAdditionalFile($path, false)
                        : $this->matcher->includesDefaultPhpFile($path);

                    if ($included) {
                        $this->record($path, $entryStat, $files);
                    }
                }
            }

            for ($offset = \count($childDirectories) - 1; $offset >= 0; --$offset) {
                $pending[] = $childDirectories[$offset];
            }
        }
    }

    /**
     * Returns the cached entry names while the directory stat data is the
     * same and not racy. Otherwise, the directory is listed again.
     *
     * @param array<array-key, int> $stat
     *
     * @return list<string>|null
     */
    private function entries(string $directory, array $stat): ?array
    {
        $cached = $this->listings[$directory] ?? null;

        if ($cached !== null && $cached[0] === $this->statKey($stat) && !$this->isRacy($stat)) {
            return $cached[1];
        }

        $entries = ErrorTrap::run(static fn() => \scandir($directory));

        if (!\is_array($entries)) {
            return null;
        }

        return \array_values(\array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
        ));
    }

    /**
     * @param array<array-key, int> $stat
     * @param array<string, array{string, string|null}> $files
     */
    private function record(string $path, array $stat, array &$files): void
    {
        if (isset($files[$path])) {
            return;
        }

        $files[$path] = [$this->statKey($stat), $this->isRacy($stat) ? $this->hash($path) : null];

        if (\count($files) > $this->maximumFiles) {
            throw WatchScanFailed::fileLimitExceeded($this->maximumFiles);
        }
    }

    /**
     * Compares stat data. Compares content hashes only when both polls saw
     * the file as racy.
     *
     * @param array{string, string|null}|null $previous
     * @param array{string, string|null} $current
     */
    private function differs(?array $previous, array $current): bool
    {
        if ($previous === null || $previous[0] !== $current[0]) {
            return true;
        }

        return $previous[1] !== null && $current[1] !== null && $previous[1] !== $current[1];
    }

    private function hash(string $path): ?string
    {
        $hash = ErrorTrap::run(fn() => ($this->hashFile)($path));

        return \is_string($hash) ? $hash : null;
    }

    /** @param array<array-key, int> $stat */
    private function isRacy(array $stat): bool
    {
        return \max($stat['mtime'], $stat['ctime']) >= $this->previousStart;
    }

    /** @param array<array-key, int> $stat */
    private function statKey(array $stat): string
    {
        return $stat['dev'] . ':' . $stat['ino'] . ':' . $stat['size'] . ':' . $stat['mtime'] . ':' . $stat['ctime'];
    }

    /** @param array<array-key, int> $stat */
    private function type(array $stat): int
    {
        return $stat['mode'] & self::TYPE_MASK;
    }

    /** @return array<array-key, int>|null */
    private function lstat(string $path): ?array
    {
        $stat = ErrorTrap::run(static fn() => \lstat($path));

        return \is_array($stat) ? $stat : null;
    }
}
