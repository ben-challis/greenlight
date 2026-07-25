<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

/**
 * Runs bin/greenlight as a subprocess and asserts on observable behaviour
 * only: exit codes and output lines.
 */
final readonly class CliTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function printsTheResolvedPlanForAFixtureConfig(): void
    {
        $result = $this->runCli(['--dry-run', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php']);
        $output = $result->outputLines();

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('  test paths: tests/Unit, tests/Acceptance')
            ->toContain('  suite unit: tests/Unit')
            ->toContain('  suite integration: tests/Integration [tags: io]')
            ->toContain('  workers: 4')
            ->toContain('  recycle: after 100 tests or above 128M memory')
            ->toContain('  stop after: 1 failure')
            ->toContain('  order: random (seed 4242)')
            ->toContain('  groups: (all)');
    }

    #[Test]
    public function commandLineFlagsOverrideTheConfigFile(): void
    {
        $result = $this->runCli([
            '--dry-run',
            '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php',
            '--workers=2',
            '--bail=7',
            '--seed=9',
            '--group=slow',
        ]);
        $output = $result->outputLines();

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('  workers: 2')
            ->toContain('  stop after: 7 failures')
            ->toContain('  order: random (seed 9)')
            ->toContain('  groups: slow');
    }

    #[Test]
    public function helpAndVersionExitZero(): void
    {
        $result = $this->runCli(['--help']);
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->output())->toContain('Usage:');

        $result = $this->runCli(['--version']);
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->outputLines())->toContain('Greenlight dev-main');
    }

    #[Test]
    public function runExecutesAPassingSuiteAndExitsZero(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['run']);
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->output())->toContain('7 tests, 7 passed');
        Expect::that($result->output())->not()->toContain('alpha:one');
    }

    #[Test]
    public function noAnsiAndVerboseAreAcceptedAndOutputStaysEscapeFree(): void
    {
        // The subprocess pipes stdout, so detection already lands on plain
        // output with or without the flag; this pins flag parsing and the
        // escape-free contract, while the TTY behaviour matrix lives in
        // TerminalCapabilitiesTest and TtyReporterTest.
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi', '--verbose']);
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->output())->not()->toContain("\x1b[");
        Expect::that($result->output())->toContain('7 tests, 7 passed');
    }

    #[Test]
    public function runExecutesAFailingSuiteAndExitsOne(): void
    {
        $result = $this->runCli(['run'], 'tests/Fixture/RunFailingConfig');

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('intentional boom');
    }

    #[Test]
    public function runWithNoTestsExitsOne(): void
    {
        $result = $this->runCli(['run'], 'tests/Fixture/RunEmptyConfig');

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('No tests found');
    }

    #[Test]
    public function listTestsPrintsDiscoveredTestIds(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['list-tests']);
        $output = $result->outputLines();
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two');
    }

    #[Test]
    public function listTestsHonoursGroupFilters(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['list-tests', '--group=slow']);
        $output = $result->outputLines();
        Expect::that($result->exitCode)->toBe(0);
        Expect::that($output)
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two')
            ->not()->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
    }

    #[Test]
    public function missingConfigFileFailsWithAnActionableMessage(): void
    {
        $result = $this->runCli([], 'tests/Fixture/ConfigFiles/Empty');

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('greenlight: No greenlight.php found in');
    }

    #[Test]
    public function unknownOptionsAreUsageErrors(): void
    {
        $result = $this->runCli(['--frobnicate']);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->output())->toContain('greenlight: Unknown option "--frobnicate"');
        Expect::that($result->output())->not()->toContain("\x1b[");
    }

    /**
     * @param list<string> $arguments
     */
    private function runCli(array $arguments, string $relativeCwd = ''): ProcessResult
    {
        $root = \dirname(__DIR__, 2);
        $cwd = $relativeCwd === '' ? $root : $root . '/' . $relativeCwd;

        return GreenlightCli::run($cwd, $arguments);
    }

}
