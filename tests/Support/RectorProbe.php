<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Fixture\TempDirectory;

/**
 * Runs the bundled PhpUnitToGreenlightRector against one PHPUnit-style test
 * class in an isolated project directory and reports the result. This
 * repository does not install PHPUnit, so the probe supplies the source
 * symbols that Rector must resolve.
 */
final readonly class RectorProbe
{
    private function __construct(
        public int $exitCode,
        public string $code,
        public bool $changed,
        public string $directory,
    ) {}

    /**
     * @param array<string, bool> $configuration PhpUnitToGreenlightRector configuration
     *
     * @throws \RuntimeException when Rector cannot run or the probe file cannot be read back
     */
    public static function convert(
        TempDirectory $workspace,
        string $testClassSource,
        array $configuration = [],
        string $name = 'rector-probe',
    ): self {
        $root = \dirname(__DIR__, 2);
        $directory = $workspace->subdirectory($name);
        $testsDirectory = $workspace->subdirectory($name . '/tests');
        $testFile = $testsDirectory . '/ProbeTest.php';

        \file_put_contents($testFile, $testClassSource);
        \file_put_contents($directory . '/rector.php', self::rectorConfig($root, $directory, $configuration));

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

        return new self($result->exitCode, $code, $code !== $testClassSource, $directory);
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
