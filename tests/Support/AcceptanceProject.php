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
 * copyOfListTestsConfig() gives acceptance tests that only need the
 * ListTestsConfig fixture's seven-test suite a private working directory, so
 * concurrent runs cannot collide on the run state file the CLI keys by
 * working directory. It cannot clone the DiscoveryBasic directory it scans:
 * that namespace is claimed by the project's own PSR-4 autoload map, so a
 * second copy of those classes would autoload from the original file and
 * fail discovery's loaded-from-the-wrong-file check. The scan target stays
 * the single shared DiscoveryBasic directory by absolute path instead.
 *
 * writeConfig() writes the common minimal greenlight.php: it requires the
 * given test files, scans the project's tests directory, and pins the worker
 * count.
 *
 * run() and runLines() invoke bin/greenlight from inside the project and
 * return the exit code with the merged stdout/stderr, joined or as raw lines.
 * runStdout() and runLinesStdout() discard stderr instead, for exact
 * assertions that must not see extension noise (Xdebug, ddtrace).
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
    private function __construct(public string $directory) {}

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

    /**
     * A private working directory configured exactly like the shared
     * ListTestsConfig fixture: the same seven tests from DiscoveryBasic,
     * scanned by absolute path rather than the original's "../DiscoveryBasic"
     * hop, so the copy needs no sibling directory of its own.
     */
    public static function copyOfListTestsConfig(string $prefix): self
    {
        $project = self::create($prefix);
        $discoveryBasic = \dirname(__DIR__) . '/Fixture/DiscoveryBasic';

        $project->write('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->paths([%s]);

            PHP,
            \var_export($discoveryBasic, true),
        ));

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
     * The generated config must never enable randomizeOrder: callers such as
     * BailRunTest and SeedOrderTest assert on declaration order in the
     * spawned run.
     *
     * @param list<string> $requireRelative test files to require, relative to the project root
     */
    public function writeConfig(array $requireRelative, int $workers = 1): void
    {
        $requires = [];

        foreach ($requireRelative as $relative) {
            $requires[] = \sprintf("require_once __DIR__ . '/%s';", $relative);
        }

        $this->write('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            %s

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(%d);

            PHP,
            \implode("\n", $requires),
            $workers,
        ));
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
     * @return array{int, string} exit code and stdout, stderr discarded
     */
    public function runStdout(string ...$arguments): array
    {
        return self::runIn($this->directory, \array_values($arguments), discardStderr: true);
    }

    /**
     * @return array{int, list<string>} exit code and stdout lines, stderr discarded
     */
    public function runLinesStdout(string ...$arguments): array
    {
        return self::runLinesIn($this->directory, \array_values($arguments), discardStderr: true);
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{int, string} exit code and output
     */
    public static function runIn(string $cwd, array $arguments, array $env = [], bool $discardStderr = false): array
    {
        [$exit, $lines] = self::runLinesIn($cwd, $arguments, $env, $discardStderr);

        return [$exit, \implode("\n", $lines)];
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     *
     * @return array{int, list<string>} exit code and output lines
     */
    public static function runLinesIn(string $cwd, array $arguments, array $env = [], bool $discardStderr = false): array
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

        $command = \sprintf(
            'cd %s && %s %s',
            \escapeshellarg($cwd),
            \implode(' ', $parts),
            $discardStderr ? '2>/dev/null' : '2>&1',
        );
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
