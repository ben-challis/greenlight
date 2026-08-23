<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Sandbox\TemporaryDirectory;

/**
 * Runs the bundled PhpUnitToGreenlightRector against PHPUnit-style test classes.
 * The probe supplies the source symbols that Rector must resolve.
 */
final readonly class RectorProbe
{
    private function __construct(
        private ProjectFiles $files,
        private string $testDirectory,
        private string $testFile,
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
        return self::convertBatch(
            $workspace,
            ['probe' => $testClassSource],
            $configuration,
            $name,
        )['probe'];
    }

    /**
     * @param array<string, string> $cases
     * @param array<string, bool>   $configuration PhpUnitToGreenlightRector configuration
     *
     * @return array<string, self>
     *
     * @throws \RuntimeException when Rector cannot run or a probe file cannot be read back
     */
    public static function convertBatch(
        TemporaryDirectory $workspace,
        array $cases,
        array $configuration = [],
        string $name = 'rector-probe',
    ): array {
        if ($cases === []) {
            throw new \InvalidArgumentException('A Rector probe batch requires at least one case.');
        }

        $root = \dirname(__DIR__, 2);
        $files = ProjectFiles::create($workspace, $name);
        $directory = $files->directory;
        $caseFiles = [];

        foreach ($cases as $caseName => $testClassSource) {
            $relativeFile = 'tests/' . self::caseDirectory($caseName) . '/ProbeTest.php';

            $files->write($relativeFile, $testClassSource);
            $caseFiles[$caseName] = $files->path($relativeFile);
        }

        $files->write('rector.php', self::rectorConfig($directory, $configuration));

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

        $probes = [];

        foreach ($caseFiles as $caseName => $testFile) {
            $code = \file_get_contents($testFile);

            if ($code === false) {
                throw new \RuntimeException(\sprintf('Could not read the probe file "%s" back.', $testFile));
            }

            $probes[$caseName] = new self(
                $files,
                \dirname($testFile),
                $testFile,
                $result->exitCode,
                $code,
                $code !== $cases[$caseName],
            );
        }

        return $probes;
    }

    /**
     * @param list<string> $arguments additional Greenlight run arguments
     */
    public function runConvertedTests(array $arguments = []): ProcessResult
    {
        $this->files->write('greenlight.php', \sprintf(
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            require_once %s;

            return GreenlightConfig::create()
                ->paths([%s])
                ->workers(1);

            PHP,
            \var_export($this->testFile, true),
            \var_export($this->testDirectory, true),
        ));

        return GreenlightCli::run(
            $this->files->directory,
            ['run', '--no-ansi', ...$arguments],
        );
    }

    /**
     * @param array<string, bool> $configuration
     */
    private static function rectorConfig(string $directory, array $configuration): string
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
            \var_export(FixturePath::get('RectorMigration/phpunit-10-plus-stubs.php'), true),
            \var_export($directory . '/tests', true),
            \var_export($directory . '/rector-cache', true),
            $rule,
        );
    }

    private static function caseDirectory(int|string $caseName): string
    {
        if (!\is_string($caseName) || $caseName === '') {
            throw new \InvalidArgumentException('A probe case name must be a nonempty string.');
        }

        $slug = \strtolower((string) \preg_replace('/[^a-z0-9]+/i', '-', $caseName));
        $slug = \trim($slug, '-');

        return ($slug === '' ? 'case' : $slug) . '-' . \substr(\hash('sha256', $caseName), 0, 8);
    }
}
