<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ExcludeSelectionTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function excludeClassRemovesOnlyTheMatchingClass(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-class=BExcludeProbeTest']);
        Expect::that($result->exitCode)->because('exclude class removes only the matching class')->toBe(0);
        $lines = $result->outputLines();
        $this->assertIds($lines, present: [
            'ExcludeProbe\AExcludeProbeTest::one',
            'ExcludeProbe\CExcludeProbeTest::one',
        ], absent: [
            'ExcludeProbe\BExcludeProbeTest::one',
        ]);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--exclude-class=BExcludeProbeTest']);
        Expect::that($result->exitCode)->because('exclude class removes only the matching class')->toBe(0)->and($result->output())->toContain('2 tests, 2 passed');
    }

    #[Test]
    public function excludeClassAcceptsAWildcard(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-class=*BExcludeProbeTest']);
        Expect::that($result->exitCode)->because('exclude class accepts a wildcard')->toBe(0);
        $lines = $result->outputLines();
        $this->assertIds($lines, present: [
            'ExcludeProbe\AExcludeProbeTest::one',
            'ExcludeProbe\CExcludeProbeTest::one',
        ], absent: [
            'ExcludeProbe\BExcludeProbeTest::one',
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
        $lines = $result->outputLines();
        $this->assertIds($lines, present: [
            'ExcludeProbe\AExcludeProbeTest::one',
            'ExcludeProbe\BExcludeProbeTest::one',
        ], absent: [
            'ExcludeProbe\CExcludeProbeTest::one',
        ]);
    }

    #[Test]
    public function excludePathResolvesARelativePrefixAgainstTheWorkingDirectory(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=tests/CExcludeProbeTest.php']);
        Expect::that($result->exitCode)->because('exclude path resolves a relative prefix against the working directory')->toBe(0);
        $lines = $result->outputLines();
        $this->assertIds($lines, present: [
            'ExcludeProbe\AExcludeProbeTest::one',
            'ExcludeProbe\BExcludeProbeTest::one',
        ], absent: [
            'ExcludeProbe\CExcludeProbeTest::one',
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
        $lines = $result->outputLines();
        $this->assertIds($lines, present: [
            'ExcludeProbe\AExcludeProbeTest::one',
            'ExcludeProbe\BExcludeProbeTest::one',
            'ExcludeProbe\CExcludeProbeTest::one',
        ], absent: [
            'ExcludeProbe\DExcludeProbeTest::one',
        ]);
    }

    #[Test]
    public function excludePathWarnsWhenThePrefixMatchesNoTestFile(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['list-tests', '--exclude-path=tests/MissingProbeTest.php']);
        Expect::that($result->exitCode)->because('exclude path warns when the prefix matches no test file')->toBe(0)
            ->and($result->output())->toContain('did not match a discovered test file')
            ->toContain('MissingProbeTest.php')
            ->toContain('3 tests');
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--exclude-path=tests/MissingProbeTest.php']);
        Expect::that($result->exitCode)->because('exclude path warns when the prefix matches no test file')->toBe(0)
            ->and($result->output())->toContain('did not match a discovered test file')
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
            ->toBe(1)
            ->and($result->output())
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
        Expect::that($result->exitCode)->because('exclude path does not warn when the prefix matches a test file')->toBe(0)
            ->and($result->output())->not()->toContain('did not match a discovered test file');
    }

    /**
     * @param list<string> $lines
     * @param list<string> $present
     * @param list<string> $absent
     */
    private function assertIds(array $lines, array $present, array $absent): void
    {
        foreach ($present as $id) {
            Expect::that($id)->toBeIn($lines);
        }

        foreach ($absent as $id) {
            Expect::that($id)->not()->toBeIn($lines);
        }
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
