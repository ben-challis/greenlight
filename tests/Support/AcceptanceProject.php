<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Sandbox\TemporaryDirectory;

final readonly class AcceptanceProject
{
    private function __construct(
        private ProjectFiles $files,
        public string $directory,
        /** @var list<non-empty-string> */
        private array $testClasses = [],
    ) {}

    public static function create(TemporaryDirectory $workspace, string $name): self
    {
        $files = ProjectFiles::create($workspace, $name, 'acceptance project');

        return new self($files, $files->directory);
    }

    /**
     * Uses the shared DiscoveryBasic directory. Do not copy its PSR-4 classes
     * because the autoloader loads copies from the original path.
     */
    public static function createWithDiscoveryBasicTests(TemporaryDirectory $workspace, string $name): self
    {
        $project = self::create($workspace, $name);
        $discoveryBasic = FixturePath::get('DiscoveryBasic');

        $project->writeFile('greenlight.php', \sprintf(
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

    public static function createWithOnePassingTest(TemporaryDirectory $workspace, string $name): self
    {
        $project = self::create($workspace, $name);
        $namespace = self::generatedNamespace($name);
        $class = 'PassingTest';
        $project->writePassingTest('tests/PassingTest.php', $namespace, $class);
        $project->configureWithTestFiles(['tests/PassingTest.php']);

        return new self($project->files, $project->directory, [$namespace . '\\' . $class]);
    }

    public static function createWithTwoPassingTests(TemporaryDirectory $workspace, string $name): self
    {
        $project = self::create($workspace, $name);
        $namespace = self::generatedNamespace($name);
        $project->writePassingTest('tests/FirstPassingTest.php', $namespace, 'FirstPassingTest');
        $project->writePassingTest('tests/SecondPassingTest.php', $namespace, 'SecondPassingTest');
        $project->configureWithTestFiles([
            'tests/FirstPassingTest.php',
            'tests/SecondPassingTest.php',
        ]);

        return new self($project->files, $project->directory, [
            $namespace . '\\FirstPassingTest',
            $namespace . '\\SecondPassingTest',
        ]);
    }

    public function path(string $relative): string
    {
        return $this->files->path($relative);
    }

    public function writeFile(string $relativePath, string $contents): void
    {
        $this->files->write($relativePath, $contents);
    }

    /** @return list<non-empty-string> */
    public function testClasses(): array
    {
        return $this->testClasses;
    }

    /**
     * Because some callers verify declaration order, keep randomizeOrder
     * disabled.
     *
     * @param list<string> $testFiles test files to require, relative to the project root
     */
    public function configureWithTestFiles(array $testFiles, int $workers = 1): void
    {
        $requires = [];

        foreach ($testFiles as $relative) {
            $requires[] = \sprintf(
                'require_once __DIR__ . %s;',
                \var_export('/' . $relative, true),
            );
        }

        $this->writeFile('greenlight.php', \sprintf(
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

    private static function generatedNamespace(string $name): string
    {
        return 'Greenlight\\Tests\\Generated\\Project' . \substr(\hash('sha256', $name), 0, 16);
    }

    private function writePassingTest(string $relativePath, string $namespace, string $class): void
    {
        $this->writeFile($relativePath, \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace %s;

            use Greenlight\Attribute\Test;

            final class %s
            {
                #[Test]
                public function passes(): void {}
            }

            PHP,
            $namespace,
            $class,
        ));
    }

}
