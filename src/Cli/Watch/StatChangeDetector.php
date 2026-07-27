<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * A portable file change detector.
 *
 * poll() records mtime and size for each PHP file in the specified
 * directories. It reports a path when its fingerprint changes. It also
 * reports new and removed paths.
 *
 * The first poll creates the initial record and reports no changes.
 *
 * @internal
 */
final class StatChangeDetector implements ChangeDetector
{
    /**
     * @var array<string, string>|null path to fingerprint
     */
    private ?array $snapshot = null;

    /**
     * @param list<non-empty-string> $directories
     */
    public function __construct(private readonly array $directories) {}

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

    /**
     * @return array<string, string>
     */
    private function scan(): array
    {
        $snapshot = [];

        foreach ($this->directories as $directory) {
            if (!\is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $file->getPathname();
                $snapshot[$path] = $file->getMTime() . ':' . $file->getSize();
            }
        }

        return $snapshot;
    }
}
