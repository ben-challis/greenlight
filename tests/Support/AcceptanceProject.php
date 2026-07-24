<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Fixture\TempDirectory;

/**
 * A project directory inside a test-owned temporary workspace.
 *
 * create() scaffolds a directory inside the injected per-test TempDirectory,
 * so the harness owns cleanup even when a test fails or throws. write() fills
 * the directory, creating parent directories as needed.
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
 */
final readonly class AcceptanceProject
{
    private function __construct(public string $directory) {}

    public static function create(TempDirectory $workspace, string $name): self
    {
        return new self($workspace->subdirectory($name));
    }

    /**
     * A private working directory configured exactly like the shared
     * ListTestsConfig fixture: the same seven tests from DiscoveryBasic,
     * scanned by absolute path rather than the original's "../DiscoveryBasic"
     * hop, so the copy needs no sibling directory of its own.
     */
    public static function copyOfListTestsConfig(TempDirectory $workspace, string $name): self
    {
        $project = self::create($workspace, $name);
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

}
