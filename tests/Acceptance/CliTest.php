<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class CliTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function printsTheResolvedPlanForAFixtureConfig(): void
    {
        $result = $this->runCli(['--dry-run', '--config=tests/Fixture/ConfigFiles/Valid/greenlight.php']);
        $output = $result->outputLines();

        Expect::that($result->exitCode)->because('prints the resolved plan for a fixture configuration')->toBe(0);
        Expect::that($output)->because('prints the resolved plan for a fixture configuration')
            ->toContain('  test paths: tests/Unit, tests/Acceptance')
            ->toContain('  suite unit: tests/Unit')
            ->toContain('  suite integration: tests/Integration [tags: io]')
            ->toContain('  workers: 4')
            ->toContain('  resource limits: postgres=3')
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
            '--resource-limit=postgres=2',
        ]);
        $output = $result->outputLines();

        Expect::that($result->exitCode)->because('command line flags override the configuration file')->toBe(0);
        Expect::that($output)->because('command line flags override the configuration file')
            ->toContain('  workers: 2')
            ->toContain('  stop after: 7 failures')
            ->toContain('  order: random (seed 9)')
            ->toContain('  groups: slow');
        Expect::that($output)->because('command line flags override the configuration file')->toContain('  resource limits: postgres=2');
    }

    #[Test]
    public function helpAndVersionExitZero(): void
    {
        $result = $this->runCli(['--help']);
        Expect::that($result->exitCode)->because('help and version exit zero')->toBe(0);
        Expect::that($result->output())->because('help and version exit zero')->toContain('Usage:');

        $result = $this->runCli(['--version']);
        Expect::that($result->exitCode)->because('help and version exit zero')->toBe(0);
        Expect::that($result->outputLines())->toContain('Greenlight dev-main');
    }

    #[Test]
    public function runExecutesAPassingSuiteAndExitsZero(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['run']);
        Expect::that($result->exitCode)->because('run executes a passing suite and exits zero')->toBe(0);
        Expect::that($result->output())->because('run executes a passing suite and exits zero')->toContain('7 tests, 7 passed');
        Expect::that($result->output())->because('run executes a passing suite and exits zero')->not()->toContain('alpha:one');
    }

    #[Test]
    public function noAnsiAndVerboseAreAcceptedAndOutputStaysEscapeFree(): void
    {
        // The subprocess pipes standard output, so detection selects plain
        // output with or without the flag. This verifies the flags and the
        // no-escape contract. TerminalCapabilitiesTest and TtyReporterTest
        // verify terminal behavior.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi', '--verbose']);
        Expect::that($result->exitCode)->because('no ANSI and verbose are accepted and output stays escape free')->toBe(0);
        Expect::that($result->output())->because('no ANSI and verbose are accepted and output stays escape free')->not()->toContain("\x1b[");
        Expect::that($result->output())->because('no ANSI and verbose are accepted and output stays escape free')->toContain('7 tests, 7 passed');
    }

    #[Test]
    public function runExecutesAFailingSuiteAndExitsOne(): void
    {
        $result = $this->runCli(['run'], 'tests/Fixture/RunFailingConfig');

        Expect::that($result->exitCode)->because('run executes a failing suite and exits one')->toBe(1);
        Expect::that($result->output())->because('run executes a failing suite and exits one')->toContain('intentional boom');
    }

    #[Test]
    public function runWithNoTestsExitsOne(): void
    {
        $result = $this->runCli(['run'], 'tests/Fixture/RunEmptyConfig');

        Expect::that($result->exitCode)->because('run with no tests exits one')->toBe(1);
        Expect::that($result->output())->because('run with no tests exits one')->toContain('Greenlight found no tests');
    }

    #[Test]
    public function listTestsPrintsDiscoveredTestIds(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['list-tests']);
        $output = $result->outputLines();
        Expect::that($result->exitCode)->because('list tests prints discovered test IDs')->toBe(0);
        Expect::that($output)->because('list tests prints discovered test IDs')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two');
    }

    #[Test]
    public function listTestsHonorsGroupFilters(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'cli');
        $result = GreenlightCli::run($project->directory, ['list-tests', '--group=slow']);
        $output = $result->outputLines();
        Expect::that($result->exitCode)->because('list tests honors group filters')->toBe(0);
        Expect::that($output)->because('list tests honors group filters')
            ->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two')
            ->not()->toContain('Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one');
    }

    #[Test]
    public function listTestsWithMissingConfigFailsWithTheExactPath(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'list-tests-missing-config');
        $projectDirectory = (string) \realpath($project->directory);
        $result = GreenlightCli::run($project->directory, ['list-tests', '--config=missing.php']);

        Expect::that($result->exitCode)
            ->because('list tests reports an explicitly missing configuration')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain('Configuration file "' . $projectDirectory . '/missing.php" does not exist.');
    }

    #[Test]
    public function missingConfigFileFailsWithAnActionableMessage(): void
    {
        $result = $this->runCli([], 'tests/Fixture/ConfigFiles/Empty');

        Expect::that($result->exitCode)->because('missing configuration file fails with an actionable message')->toBe(1);
        Expect::that($result->output())->because('missing configuration file fails with an actionable message')->toContain('greenlight: No greenlight.php found in');
    }

    #[Test]
    public function unknownOptionsAreUsageErrors(): void
    {
        $result = $this->runCli(['--frobnicate']);

        Expect::that($result->exitCode)->because('unknown options are usage errors')->toBe(64);
        Expect::that($result->output())->because('unknown options are usage errors')->toContain('greenlight: Unknown option "--frobnicate"');
        Expect::that($result->output())->because('unknown options are usage errors')->not()->toContain("\x1b[");
    }

    /**
     * @param list<string> $arguments
     */
    #[Test]
    #[DataSet('invalidWorkerEntries')]
    public function invalidInternalWorkerEntriesAreUsageErrors(array $arguments): void
    {
        $result = $this->runCli($arguments);

        Expect::that($result->exitCode)
            ->because('an invalid internal worker entry MUST stop before connection')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toBe('__worker requires <address> <workerId> <token>.');
        Expect::that($result->stdout)
            ->toBe('');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function invalidWorkerEntries(): iterable
    {
        yield 'missing arguments' => [['__worker']];
        yield 'empty address' => [['__worker', '', 'worker-1', 'secret-token']];
        yield 'empty worker ID' => [['__worker', 'tcp://127.0.0.1:1', '', 'secret-token']];
        yield 'empty token' => [['__worker', 'tcp://127.0.0.1:1', 'worker-1', '']];
    }

    #[Test]
    public function anInternalWorkerConnectionFailureExitsWithADiagnostic(): void
    {
        $result = $this->runCli([
            '__worker',
            'invalid://worker',
            'worker-1',
            'secret-token',
        ]);

        Expect::that($result->exitCode)
            ->because('an internal worker connection failure MUST report a failed process')
            ->toBe(1);
        Expect::that($result->stderr)
            ->toStartWith('The worker did not connect to invalid://worker:');
        Expect::that($result->stdout)
            ->toBe('');
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
