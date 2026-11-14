<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Composer\InstalledVersions;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Internal\Filesystem\AtomicFile;
use Greenlight\Internal\Filesystem\AtomicFileError;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Stores one discovery cache entry for each file. The path, modification time,
 * size, and content hash of the file identify an entry. The entry also tracks
 * the files of parent classes, traits, interfaces, provider classes, and
 * classes that attribute constant expressions name.
 *
 * An entry contains one plan entry for each test definition, without data-set
 * keys. Discovery expands data sets again on each run, because provider output
 * can change without a change to a tracked file. Entries do not contain filter
 * results. Thus, a filter change does not require another parse operation.
 *
 * The cache identity includes the PHP version and the Greenlight version.
 * Discovery parses the file after a cache miss, stale file, corrupt cache, or
 * identity mismatch.
 *
 * Each object reads the status of a path one time. The persist() method
 * writes the file only if the entry set changed.
 *
 * @internal
 */
final class DiscoveryCache
{
    private const int VERSION = 3;

    /**
     * @var array<string, DiscoveryCacheEntry>
     */
    private array $files = [];

    /**
     * @var array<string, DiscoveryCacheEntry>
     */
    private array $touched = [];

    private bool $loaded = false;

    private bool $changed = false;

    /**
     * @var array<string, array{mtime: int, size: int, contentHash: ?non-empty-string}|null>
     */
    private array $stats = [];

    private function __construct(private readonly string $file) {}

    /**
     * @param list<non-empty-string> $directories
     */
    public static function forDirectories(array $directories, ?string $cacheDirectory = null): self
    {
        $sorted = $directories;
        \sort($sorted);

        $cacheDirectory ??= \sys_get_temp_dir();

        return new self(\sprintf(
            '%s/greenlight-discovery-%s.json',
            \rtrim($cacheDirectory, '/'),
            \substr(\sha1(\implode("\n", $sorted)), 0, 12),
        ));
    }

    /**
     * Returns cached definition entries without data-set keys or filter results.
     *
     * Returns null if the cache entry is not valid.
     *
     * @param non-empty-string $file
     *
     * @return list<PlanEntry>|null
     */
    public function lookup(string $file): ?array
    {
        $this->load();

        $cached = $this->files[$file] ?? null;

        if ($cached === null) {
            return null;
        }

        $stat = $this->stat($file);

        if (
            $stat === null
            || $stat['mtime'] !== $cached->mtime
            || $stat['size'] !== $cached->size
            || $stat['contentHash'] !== $cached->contentHash
        ) {
            return null;
        }

        foreach ($cached->dependencies as $path => $dependency) {
            $stat = $this->stat($path);

            if ($stat === null || $stat !== $dependency) {
                return null;
            }
        }

        $entries = $cached->planEntries();

        if ($entries === null) {
            return null;
        }

        $this->touched[$file] = $cached;

        return $entries;
    }

    /**
     * @param non-empty-string $file
     * @param list<PlanEntry> $entries One entry for each test definition, without data-set keys.
     * @param class-string|null $class The declared class, including classes with no test entries.
     */
    public function store(string $file, array $entries, ?string $class = null): void
    {
        $stat = $this->stat($file);

        if ($stat === null) {
            return;
        }

        $this->changed = true;

        $this->touched[$file] = new DiscoveryCacheEntry(
            $stat['mtime'],
            $stat['size'],
            \array_map(static fn(PlanEntry $entry): array => $entry->toWire(), $entries),
            $this->dependencies($file, $entries, $class),
            $stat['contentHash'],
        );
    }

    /**
     * @param list<PlanEntry> $entries
     * @param class-string|null $class
     *
     * @return array<non-empty-string, array{mtime: int, size: int, contentHash?: string}>
     */
    private function dependencies(string $source, array $entries, ?string $class): array
    {
        $classes = $class === null ? [] : [$class => true];

        foreach ($entries as $entry) {
            $classes[$entry->definition->class] = true;

            if ($entry->definition->dataProvider->class !== null) {
                $classes[$entry->definition->dataProvider->class] = true;
            }
        }

        $pending = [];

        foreach (\array_keys($classes) as $name) {
            if (\class_exists($name, false)) {
                $pending[] = new \ReflectionClass($name);
            }
        }

        $seen = [];
        $files = [];
        $scanned = [];

        while ($pending !== []) {
            $reflection = \array_pop($pending);
            $name = $reflection->getName();

            if (isset($seen[$name])) {
                continue;
            }

            $seen[$name] = true;
            $file = $reflection->getFileName();

            if (\is_string($file) && $file !== '') {
                if ($file !== $source) {
                    $files[$file] = true;
                }

                if (!isset($scanned[$file])) {
                    $scanned[$file] = true;

                    foreach (AttributeClassReferences::in($file) as $referenced) {
                        if (
                            \class_exists($referenced, false)
                            || \interface_exists($referenced, false)
                            || \enum_exists($referenced, false)
                        ) {
                            $pending[] = new \ReflectionClass($referenced);
                        }
                    }
                }
            }

            $parent = $reflection->getParentClass();

            if ($parent !== false) {
                $pending[] = $parent;
            }

            foreach ($reflection->getTraits() as $trait) {
                $pending[] = $trait;
            }

            foreach ($reflection->getInterfaces() as $interface) {
                $pending[] = $interface;
            }
        }

        $dependencies = [];

        foreach (\array_keys($files) as $file) {
            $stat = $this->stat($file);

            if ($stat !== null) {
                $dependencies[$file] = $stat['contentHash'] === null
                    ? ['mtime' => $stat['mtime'], 'size' => $stat['size']]
                    : ['mtime' => $stat['mtime'], 'size' => $stat['size'], 'contentHash' => $stat['contentHash']];
            }
        }

        return $dependencies;
    }

    /**
     * @return array{mtime: int, size: int, contentHash: ?non-empty-string}|null
     */
    private function stat(string $file): ?array
    {
        if (\array_key_exists($file, $this->stats)) {
            return $this->stats[$file];
        }

        \clearstatcache(true, $file);

        return $this->stats[$file] = ErrorTrap::run(static function () use ($file) {
            $mtime = \filemtime($file);
            $size = \filesize($file);
            $contentHash = \sha1_file($file);

            if (!\is_int($mtime) || !\is_int($size)) {
                return null;
            }

            return [
                'mtime' => $mtime,
                'size' => $size,
                'contentHash' => \is_string($contentHash) ? $contentHash : null,
            ];
        });
    }

    /**
     * Writes entries for files that this discovery reads and removes older entries.
     * Does nothing if each lookup was a hit for the same set of files.
     *
     * Returns false on write failure.
     */
    public function persist(): bool
    {
        if ($this->touched === []) {
            return true;
        }

        if (!$this->changed && \count($this->touched) === \count($this->files)) {
            return true;
        }

        try {
            $encoded = \json_encode(
                ['version' => self::VERSION, 'identity' => $this->identity(), 'files' => $this->touched],
                \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
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

        return true;
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $file = $this->file;
        $raw = ErrorTrap::run(static fn() => \file_get_contents($file));

        if (!\is_string($raw)) {
            return;
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (
            !\is_array($decoded)
            || ($decoded['version'] ?? null) !== self::VERSION
            || ($decoded['identity'] ?? null) !== $this->identity()
            || !\is_array($decoded['files'] ?? null)
        ) {
            return;
        }

        foreach ($decoded['files'] as $path => $entry) {
            if (!\is_string($path) || !\is_array($entry)) {
                continue;
            }

            $normalized = DiscoveryCacheEntry::fromDecoded($entry);

            if ($normalized instanceof DiscoveryCacheEntry) {
                $this->files[$path] = $normalized;
            }
        }
    }

    /**
     * Identifies the PHP and Greenlight builds that wrote the cache. Constant
     * expressions in attributes can branch on PHP_VERSION_ID, and a Greenlight
     * update can change discovery metadata.
     */
    private function identity(): string
    {
        $greenlight = InstalledVersions::isInstalled('greenlight/greenlight')
            ? (InstalledVersions::getPrettyVersion('greenlight/greenlight') ?? '') . '@' . (InstalledVersions::getReference('greenlight/greenlight') ?? '')
            : 'unknown';

        return \PHP_VERSION . ' ' . $greenlight;
    }
}
