<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/**
 * A throwaway project directory for acceptance tests that drive the real CLI.
 *
 * create() scaffolds a uniquely named directory under the system temp dir and
 * copyOf() clones a committed fixture project into one; write() fills the
 * directory, creating parent directories as needed.
 *
 * run() and runLines() invoke bin/greenlight from inside the project and
 * return the exit code with the merged stdout/stderr, joined or as raw lines.
 * runIn() and runLinesIn() do the same from an arbitrary working directory,
 * such as a fixture project inside the repository, with optional environment
 * variable overrides.
 *
 * remove() deletes the project tree recursively, so nested artefacts like
 * caches, sockets, and var directories cannot leak; removeTree() offers the
 * same for directories not owned by an instance and tolerates missing ones.
 */
final readonly class AcceptanceProject
{
    private function __construct(
        public string $directory,
    ) {}

    public static function create(string $prefix): self
    {
        $directory = \sys_get_temp_dir() . '/greenlight-' . $prefix . '-' . \bin2hex(\random_bytes(6));
        \mkdir($directory, 0o777, true);

        return new self($directory);
    }

    public static function copyOf(string $source, string $prefix): self
    {
        $project = self::create($prefix);

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);
            $destination = $project->directory . '/' . \substr($entry->getPathname(), \strlen($source) + 1);

            if ($entry->isDir()) {
                \mkdir($destination, 0o777, true);
            } else {
                \copy($entry->getPathname(), $destination);
            }
        }

        return $project;
    }

    public function path(string $relative): string
    {
        return $this->directory . '/' . $relative;
    }

    public function write(string $relativePath, string $contents): void
    {
        $path = $this->path($relativePath);
        $parent = \dirname($path);

        if (!\is_dir($parent)) {
            \mkdir($parent, 0o777, true);
        }

        \file_put_contents($path, $contents);
    }

    /**
     * @return array{int, string} exit code and merged output
     */
    public function run(string ...$arguments): array
    {
        return self::runIn($this->directory, \array_values($arguments));
    }

    /**
     * @return array{int, list<string>} exit code and merged output lines
     */
    public function runLines(string ...$arguments): array
    {
        return self::runLinesIn($this->directory, \array_values($arguments));
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{int, string} exit code and merged output
     */
    public static function runIn(string $cwd, array $arguments, array $env = []): array
    {
        [$exit, $lines] = self::runLinesIn($cwd, $arguments, $env);

        return [$exit, \implode("\n", $lines)];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{int, list<string>} exit code and merged output lines
     */
    public static function runLinesIn(string $cwd, array $arguments, array $env = []): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [];

        foreach ($env as $name => $value) {
            $parts[] = \sprintf('%s=%s', $name, \escapeshellarg($value));
        }

        $parts[] = \escapeshellarg(\PHP_BINARY);
        $parts[] = \escapeshellarg($root . '/bin/greenlight');

        foreach ($arguments as $argument) {
            $parts[] = \escapeshellarg($argument);
        }

        $command = \sprintf('cd %s && %s 2>&1', \escapeshellarg($cwd), \implode(' ', $parts));
        \exec($command, $output, $exit);

        return [$exit, $output];
    }

    public function remove(): void
    {
        self::removeTree($this->directory);
    }

    public static function removeTree(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            if ($entry->isDir() && !$entry->isLink()) {
                @\rmdir($entry->getPathname());
            } else {
                @\unlink($entry->getPathname());
            }
        }

        @\rmdir($directory);
    }
}
