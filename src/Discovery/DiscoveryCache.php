<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Core\Wire\InvalidWirePayload;

/**
 * Per-file cache of derived plan entries, keyed by path, mtime, and size.
 *
 * A hit lets an unchanged file skip parsing, class loading, and attribute
 * reflection on the next discovery. Watch mode benefits most: its iterations
 * re-discover constantly. Entries are cached unfiltered; filters apply after
 * load, so a changed filter never needs a re-parse.
 *
 * Correctness rule: any doubt (missing file, mtime or size mismatch, corrupt
 * cache, version bump) falls back to parsing. One soft spot is inherent: a
 * data-set provider whose output changes without its file changing (against
 * the purity contract) yields stale keys, which the worker-side revalidation
 * turns into a loud per-test error rather than wrong data.
 *
 * The cache lives under the system temp dir keyed by a hash of the scanned
 * directories, the same convention as the proxy cache and run state.
 *
 * @internal
 */
final class DiscoveryCache
{
    private const int VERSION = 1;

    /**
     * @var array<string, array{mtime: int, size: int, entries: list<array<string, mixed>>}>
     */
    private array $files = [];

    /**
     * @var array<string, array{mtime: int, size: int, entries: list<array<string, mixed>>}>
     */
    private array $touched = [];

    private bool $loaded = false;

    private function __construct(
        private readonly string $file,
    ) {}

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
     * Cached unfiltered entries for a file, or null on any doubt.
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

        $mtime = @\filemtime($file);
        $size = @\filesize($file);

        if ($mtime !== $cached['mtime'] || $size !== $cached['size']) {
            return null;
        }

        $entries = [];

        try {
            foreach ($cached['entries'] as $payload) {
                $entries[] = PlanEntry::fromWire($payload);
            }
        } catch (\InvalidArgumentException|InvalidWirePayload) {
            // Undecodable cached payload; treat as a miss and re-parse.
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
        $mtime = @\filemtime($file);
        $size = @\filesize($file);

        if (!\is_int($mtime) || !\is_int($size)) {
            return;
        }

        $this->touched[$file] = [
            'mtime' => $mtime,
            'size' => $size,
            'entries' => \array_map(static fn(PlanEntry $entry): array => $entry->toWire(), $entries),
        ];
    }

    /**
     * Persists every file this discovery saw (hit or stored), pruning files
     * that no longer exist in the scan, so the cache tracks the suite.
     */
    public function persist(): void
    {
        if ($this->touched === []) {
            return;
        }

        @\file_put_contents($this->file, \json_encode(
            ['version' => self::VERSION, 'files' => $this->touched],
            \JSON_UNESCAPED_SLASHES,
        ));
    }

    private function load(): void
    {
        if ($this->loaded) {
            return;
        }

        $this->loaded = true;
        $raw = @\file_get_contents($this->file);

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

            if (!\is_int($entry['mtime'] ?? null) || !\is_int($entry['size'] ?? null) || !\is_array($entry['entries'] ?? null)) {
                continue;
            }

            $payloads = [];

            foreach ($entry['entries'] as $payload) {
                if (!\is_array($payload)) {
                    continue 2;
                }

                $normalized = [];

                foreach ($payload as $key => $value) {
                    if (!\is_string($key)) {
                        continue 3;
                    }

                    $normalized[$key] = $value;
                }

                $payloads[] = $normalized;
            }

            $this->files[$path] = [
                'mtime' => $entry['mtime'],
                'size' => $entry['size'],
                'entries' => $payloads,
            ];
        }
    }
}
