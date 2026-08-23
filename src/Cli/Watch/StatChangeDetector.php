<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * A portable file change detector.
 *
 * poll() records the modification time, size, and content hash for each PHP
 * file in the specified directories. It reports a path when its fingerprint
 * changes. It also reports new and removed paths.
 *
 * The first poll creates the initial record and reports no changes.
 *
 * @internal
 */
final class StatChangeDetector implements ChangeDetector
{
    /**
     * @var array<non-empty-string, array{fingerprint: non-empty-string, contents: ?string}>|null
     */
    private ?array $snapshot = null;

    /**
     * @param list<string> $directories
     * @param list<string> $contentRoots
     * @param list<string> $files
     */
    public function __construct(
        private readonly array $directories,
        private readonly array $contentRoots = [],
        private readonly array $files = [],
    ) {}

    #[\Override]
    public function poll(): array
    {
        $current = $this->scan();

        if ($this->snapshot === null) {
            $this->snapshot = $current;

            return [];
        }

        $changed = [];

        foreach ($current as $path => $state) {
            $previous = $this->snapshot[$path] ?? null;

            if ($previous === null) {
                $changed[] = new FileChange($path, false, true, after: $state['contents']);
            } elseif ($previous['fingerprint'] !== $state['fingerprint']) {
                $changed[] = new FileChange($path, true, true, $previous['contents'], $state['contents']);
            }
        }

        foreach (\array_keys($this->snapshot) as $path) {
            if (!isset($current[$path])) {
                $changed[] = new FileChange($path, true, false, $this->snapshot[$path]['contents']);
            }
        }

        $this->snapshot = $current;

        return $changed;
    }

    /**
     * @return array<non-empty-string, array{fingerprint: non-empty-string, contents: ?string}>
     */
    private function scan(): array
    {
        $snapshot = [];

        foreach ($this->directories as $directory) {
            try {
                $files = ErrorTrap::run(fn() => $this->scanDirectory($directory));
            } catch (\UnexpectedValueException) {
                continue;
            }

            foreach ($files as $path => $state) {
                if ($path !== '') {
                    $snapshot[$path] = $state;
                }
            }
        }

        foreach ($this->files as $file) {
            if ($file === '') {
                continue;
            }

            $state = $this->fileState($file);

            if ($state !== null) {
                $snapshot[$file] = $state;
            }
        }

        return $snapshot;
    }

    /**
     * @return array<non-empty-string, array{fingerprint: non-empty-string, contents: ?string}>
     */
    private function scanDirectory(string $directory): array
    {
        if (!\is_dir($directory)) {
            return [];
        }

        $snapshot = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $path = $file->getPathname();
            $state = $this->fileState($path);

            if ($path !== '' && $state !== null) {
                $snapshot[$path] = $state;
            }
        }

        return $snapshot;
    }

    /** @return array{fingerprint: non-empty-string, contents: ?string}|null */
    private function fileState(string $path): ?array
    {
        \clearstatcache(true, $path);

        return ErrorTrap::run(function () use ($path) {
            $mtime = \filemtime($path);
            $size = \filesize($path);
            $contentHash = \sha1_file($path);

            if (!\is_int($mtime) || !\is_int($size) || !\is_string($contentHash)) {
                return null;
            }

            $fingerprint = $mtime . ':' . $size . ':' . $contentHash;

            return [
                'fingerprint' => $fingerprint,
                'contents' => $this->capturesContents($path) ? $this->contents($path) : null,
            ];
        });
    }

    private function capturesContents(string $path): bool
    {
        return \array_any($this->contentRoots, static function (string $root) use ($path): bool {
            $prefix = \rtrim($root, '/') . '/';

            return $path === \rtrim($root, '/') || \str_starts_with($path, $prefix);
        });
    }

    private function contents(string $path): ?string
    {
        $contents = ErrorTrap::run(static fn() => \file_get_contents($path));

        return \is_string($contents) ? $contents : null;
    }
}
