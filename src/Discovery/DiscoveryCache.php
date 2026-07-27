<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Wire\InvalidWirePayload;

/**
 * Stores one discovery cache entry for each file. The path, mtime, size, and
 * external provider files identify an entry. Entries do not contain filter
 * results. Thus, a filter change does not require another parse operation.
 *
 * Discovery parses the file after a cache miss, stale file, corrupt cache, or
 * version mismatch. A data provider can change output without a file change.
 * In this case, worker validation rejects stale keys.
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

    private function __construct(private readonly string $file) {}

    /**
     * @param list<non-empty-string> $directories
     */
    public static function forDirectories(array $directories): self
    {
        $sorted = $directories;
        \sort($sorted);

        return new self(\sprintf(
            '%s/greenlight-discovery-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1(\implode("\n", $sorted)), 0, 12),
        ));
    }

    /**
     * Returns cached entries without filter results.
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

        if ($stat === null || $stat['mtime'] !== $cached->mtime || $stat['size'] !== $cached->size) {
            return null;
        }

        foreach ($cached->dependencies as $path => $dependency) {
            $stat = $this->stat($path);

            if ($stat === null || $stat !== $dependency) {
                return null;
            }
        }

        $entries = [];

        try {
            foreach ($cached->entries as $payload) {
                $entries[] = PlanEntry::fromWire($payload);
            }
        } catch (\InvalidArgumentException|InvalidWirePayload) {
            // Treat a cache payload that cannot decode as a cache miss.
            return null;
        }

        $this->touched[$file] = $cached;

        return $entries;
    }

    /**
     * @param non-empty-string $file
     * @param list<PlanEntry> $entries
     */
    public function store(string $file, array $entries): void
    {
        $stat = $this->stat($file);

        if ($stat === null) {
            return;
        }

        $this->touched[$file] = new DiscoveryCacheEntry(
            $stat['mtime'],
            $stat['size'],
            \array_map(static fn(PlanEntry $entry): array => $entry->toWire(), $entries),
            $this->providerDependencies($entries),
        );
    }

    /**
     * @param list<PlanEntry> $entries
     *
     * @return array<non-empty-string, array{mtime: int, size: int}>
     */
    private function providerDependencies(array $entries): array
    {
        $dependencies = [];

        foreach ($entries as $entry) {
            $class = $entry->metadata->dataSetProviderClass;

            if ($class === null || !\class_exists($class)) {
                continue;
            }

            $reflection = new \ReflectionClass($class);
            $files = [$reflection->getFileName()];
            $provider = $entry->metadata->dataSetProvider;

            if ($provider !== null && $reflection->hasMethod($provider)) {
                $files[] = $reflection->getMethod($provider)->getFileName();
            }

            foreach ($files as $file) {
                if (!\is_string($file) || $file === '') {
                    continue;
                }

                $stat = $this->stat($file);

                if ($stat !== null) {
                    $dependencies[$file] = $stat;
                }
            }
        }

        return $dependencies;
    }

    /**
     * @return array{mtime: int, size: int}|null
     */
    private function stat(string $file): ?array
    {
        return ErrorTrap::run(static function () use ($file): ?array {
            $mtime = \filemtime($file);
            $size = \filesize($file);

            if (!\is_int($mtime) || !\is_int($size)) {
                return null;
            }

            return ['mtime' => $mtime, 'size' => $size];
        });
    }

    /**
     * Writes entries for files that this discovery reads and removes older entries.
     *
     * Returns false on write failure.
     */
    public function persist(): bool
    {
        if ($this->touched === []) {
            return true;
        }

        try {
            $encoded = \json_encode(
                ['version' => self::VERSION, 'files' => $this->touched],
                \JSON_UNESCAPED_SLASHES | \JSON_THROW_ON_ERROR,
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

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $file = $this->file;
        $raw = ErrorTrap::run(static fn(): string|false => \file_get_contents($file));

        if (!\is_string($raw)) {
            return;
        }

        try {
            $decoded = \json_decode($raw, true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return;
        }

        if (!\is_array($decoded) || ($decoded['version'] ?? null) !== self::VERSION || !\is_array($decoded['files'] ?? null)) {
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
}
