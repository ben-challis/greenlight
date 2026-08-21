<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ExcludeSelectionTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function excludeClassRemovesOnlyTheMatchingClass(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-class=BExcludeProbeTest']);

        Expect::that($result->exitCode)->because('exclude class removes only the matching class')->toBe(0);
        Expect::that($this->selectedTestIds($result->stdoutLines()))
            ->because('exclude class removes only the matching class')
            ->toBe([
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\CExcludeProbeTest::one',
            ]);
    }

    #[Test]
    public function excludeClassAcceptsAWildcard(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-class=*BExcludeProbeTest']);

        Expect::that($result->exitCode)->because('exclude class accepts a wildcard')->toBe(0);
        Expect::that($this->selectedTestIds($result->stdoutLines()))
            ->because('exclude class accepts a wildcard')
            ->toBe([
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\CExcludeProbeTest::one',
            ]);
    }

    #[Test]
    public function excludePathRemovesTestsUnderThatPrefix(): void
    {
        $project = $this->writeProject();
        // Use realpath(), not project->path(). Discovery reports the absolute
        // path after symbolic-link resolution. On macOS, temporary paths can
        // have aliases. The prefix comparison is exact.
        $excludedFile = (string) \realpath($project->path('tests/CExcludeProbeTest.php'));
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=' . $excludedFile]);

        Expect::that($result->exitCode)->because('exclude path removes tests under that prefix')->toBe(0);
        Expect::that($this->selectedTestIds($result->stdoutLines()))
            ->because('exclude path removes tests under that prefix')
            ->toBe([
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\BExcludeProbeTest::one',
            ]);
    }

    #[Test]
    public function excludePathResolvesARelativePrefixAgainstTheWorkingDirectory(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=tests/CExcludeProbeTest.php']);

        Expect::that($result->exitCode)->because('exclude path resolves a relative prefix against the working directory')->toBe(0);
        Expect::that($this->selectedTestIds($result->stdoutLines()))
            ->because('exclude path resolves a relative prefix against the working directory')
            ->toBe([
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\BExcludeProbeTest::one',
            ]);
    }

    #[Test]
    public function excludePathResolvesARelativeDirectoryPrefix(): void
    {
        $project = $this->writeProject();
        $project->writeFile('tests/nested/DExcludeProbeTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace ExcludeProbe;

            use Greenlight\Attribute\Test;

            final class DExcludeProbeTest
            {
                #[Test]
                public function one(): void {}
            }
            PHP);
        $project->configureWithTestFiles([
            'tests/AExcludeProbeTest.php',
            'tests/BExcludeProbeTest.php',
            'tests/CExcludeProbeTest.php',
            'tests/nested/DExcludeProbeTest.php',
        ]);
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=tests/nested']);

        Expect::that($result->exitCode)->because('exclude path resolves a relative directory prefix')->toBe(0);
        Expect::that($this->selectedTestIds($result->stdoutLines()))
            ->because('exclude path resolves a relative directory prefix')
            ->toBe([
                'ExcludeProbe\AExcludeProbeTest::one',
                'ExcludeProbe\BExcludeProbeTest::one',
                'ExcludeProbe\CExcludeProbeTest::one',
            ]);
    }

    #[Test]
    public function restrictedExcludePathDoesNotLeakEngineDiagnostics(): void
    {
        $project = $this->writeProject();
        $root = \dirname(__DIR__, 2);
        $restricted = \dirname($root);
        $result = GreenlightCli::run(
            $project->directory,
            ['list-tests', '--exclude-path=' . $restricted],
            phpArguments: [
                '-d',
                'open_basedir=' . $root . \PATH_SEPARATOR . \sys_get_temp_dir(),
            ],
        );

        Expect::that($result->exitCode)
            ->because('a restricted exclusion prefix MUST not prevent test selection')
            ->toBe(0);
        Expect::that($result->output())
            ->because('a restricted exclusion prefix MUST not leak engine diagnostics')
            ->not()
            ->toContain('open_basedir restriction in effect');
    }

    #[Test]
    public function excludePathWarnsWhenThePrefixMatchesNoTestFile(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=tests/MissingProbeTest.php']);

        Expect::that($result->exitCode)->because('exclude path warns when the prefix matches no test file')->toBe(0);
        Expect::that($result->output())
            ->because('exclude path warns when the prefix matches no test file')
            ->toContain('did not match a discovered test file')
            ->toContain('MissingProbeTest.php')
            ->toContain('3 tests');

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--exclude-path=tests/MissingProbeTest.php']);

        Expect::that($result->exitCode)->because('exclude path warns when the prefix matches no test file')->toBe(0);
        Expect::that($result->output())
            ->because('exclude path warns when the prefix matches no test file')
            ->toContain('did not match a discovered test file')
            ->toContain('3 tests, 3 passed');
    }

    #[Test]
    public function excludePathWarningIsSuppressedWhenDiscoveryFails(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'exclude-path-discovery-error');
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/missing-tests']);

            PHP);

        $result = GreenlightCli::run(
            $project->directory,
            ['list-tests', '--exclude-path=tests/MissingProbeTest.php'],
        );

        Expect::that($result->exitCode)
            ->because('the discovery failure remains the command error')
            ->toBe(1);
        Expect::that($result->output())
            ->because('the discovery failure remains the command error')
            ->toContain('Discovery directory')
            ->toContain('missing-tests')
            ->not()
            ->toContain('did not match a discovered test file');
    }

    #[Test]
    public function excludePathDoesNotWarnWhenThePrefixMatchesATestFile(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=tests/CExcludeProbeTest.php']);

        Expect::that($result->exitCode)->because('exclude path does not warn when the prefix matches a test file')->toBe(0);
        Expect::that($result->output())
            ->because('exclude path does not warn when the prefix matches a test file')
            ->not()
            ->toContain('did not match a discovered test file');
    }

    /**
     * @param list<string> $lines
     *
     * @return list<string>
     */
    private function selectedTestIds(array $lines): array
    {
        return \array_values(\array_filter(
            $lines,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'exclude-selection');

        foreach (['A', 'B', 'C'] as $letter) {
            $project->writeFile(\sprintf('tests/%sExcludeProbeTest.php', $letter), <<<PHP
                <?php

                declare(strict_types=1);

                namespace ExcludeProbe;

                use Greenlight\Attribute\Test;

                final class {$letter}ExcludeProbeTest
                {
                    #[Test]
                    public function one(): void {}
                }
                PHP);
        }

        $project->configureWithTestFiles([
            'tests/AExcludeProbeTest.php',
            'tests/BExcludeProbeTest.php',
            'tests/CExcludeProbeTest.php',
        ]);

        return $project;
    }
}
