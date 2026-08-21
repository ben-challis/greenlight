<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Sandbox\TemporaryDirectory;

/**
 * Runs the bundled PhpUnitToGreenlightRector against one PHPUnit-style test
 * class in an isolated project directory and reports the result. This
 * repository does not install PHPUnit, so the probe supplies the source
 * symbols that Rector must resolve.
 */
final readonly class RectorProbe
{
    private function __construct(
        private ProjectFiles $files,
        public int $exitCode,
        public string $code,
        public bool $changed,
    ) {}

    /**
     * @param array<string, bool> $configuration PhpUnitToGreenlightRector configuration
     *
     * @throws \RuntimeException when Rector cannot run or the probe file cannot be read back
     */
    public static function convert(
        TemporaryDirectory $workspace,
        string $testClassSource,
        array $configuration = [],
        string $name = 'rector-probe',
    ): self {
        $root = \dirname(__DIR__, 2);
        $files = ProjectFiles::create($workspace, $name);
        $directory = $files->directory;
        $testsDirectory = $directory . '/tests';
        $testFile = $testsDirectory . '/ProbeTest.php';

        $files->write('tests/ProbeTest.php', $testClassSource);
        $files->write('rector.php', self::rectorConfig($root, $directory, $configuration));

        $result = Subprocess::run(
            $root,
            [
                \PHP_BINARY,
                $root . '/vendor/bin/rector',
                'process',
                '--config',
                $directory . '/rector.php',
                '--no-progress-bar',
                '--no-ansi',
            ],
        );

        if ($result->exitCode !== 0) {
            throw new \RuntimeException(\sprintf(
                "Rector exited with %d.\nStdout:\n%s\nStderr:\n%s",
                $result->exitCode,
                $result->stdout,
                $result->stderr,
            ));
        }

        $code = \file_get_contents($testFile);

        if ($code === false) {
            throw new \RuntimeException(\sprintf('Could not read the probe file "%s" back.', $testFile));
        }

        return new self($files, $result->exitCode, $code, $code !== $testClassSource);
    }

    /**
     * @param list<string> $arguments additional Greenlight run arguments
     */
    public function runConvertedTests(array $arguments = []): ProcessResult
    {
        $this->files->write('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once __DIR__ . '/tests/ProbeTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers(1);

            PHP);

        return GreenlightCli::run(
            $this->files->directory,
            ['run', '--no-ansi', ...$arguments],
        );
    }

    /**
     * @param array<string, bool> $configuration
     */
    private static function rectorConfig(string $root, string $directory, array $configuration): string
    {
        $rule = $configuration === []
            ? '->withRules([PhpUnitToGreenlightRector::class])'
            : \sprintf(
                '->withConfiguredRule(PhpUnitToGreenlightRector::class, %s)',
                \var_export($configuration, true),
            );

        return \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Rector\PhpUnitToGreenlightRector;
            use Rector\Config\RectorConfig;

            require %s;

            return RectorConfig::configure()
                ->withPaths([%s])
                ->withCache(%s)
                %s;

            PHP,
            \var_export($root . '/tests/Fixture/RectorMigration/phpunit-10-plus-stubs.php', true),
            \var_export($directory . '/tests', true),
            \var_export($directory . '/rector-cache', true),
            $rule,
        );
    }
}
