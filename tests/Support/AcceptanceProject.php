<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Fixture\TempDirectory;

final readonly class AcceptanceProject
{
    private function __construct(public string $directory) {}

    public static function create(TempDirectory $workspace, string $name): self
    {
        return new self($workspace->subdirectory($name));
    }

    /**
     * Uses the shared DiscoveryBasic directory because copying its PSR-4
     * classes would make them autoload from the original path.
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
     * Keep randomizeOrder disabled. Some callers assert declaration order.
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
