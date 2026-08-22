<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class IntegrationFixtureRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function parallelWorkersReceiveChannelResourcesAndCleanup(): void
    {
        $project = $this->writeProject('parallel', workers: 2);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        $bootstrapped = $this->lines($project->path('markers/bootstrapped.log'));
        $resources = $this->matches($project->path('markers/resource-*'));

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($result->output())
            ->toContain('4 tests, 4 passed')
            ->not()->toContain('fixture-secret');
        Expect::that($bootstrapped)->toHaveCount(2);
        Expect::that($resources)->toBe([]);
        Expect::that($this->lines($project->path('markers/provisioned.log')))->toHaveCount(1);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function inProcessFailuresStillTearDownTheFixture(): void
    {
        $project = $this->writeProject('failure', workers: 1, failing: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->toContain('intentional failure')
            ->not()->toContain('fixture-secret');
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function inspectionCommandsDoNotProvisionInfrastructure(): void
    {
        $project = $this->writeProject('listing', workers: 2);
        $listTests = GreenlightCli::run($project->directory, ['list-tests']);
        $listGroups = GreenlightCli::run($project->directory, ['run', '--list-groups']);
        $listSuites = GreenlightCli::run($project->directory, ['run', '--list-suites']);
        $dryRun = GreenlightCli::run($project->directory, ['run', '--dry-run']);

        Expect::that($listTests->exitCode)->toBe(0);
        Expect::that($listGroups->exitCode)->toBe(0);
        Expect::that($listSuites->exitCode)->toBe(0);
        Expect::that($dryRun->exitCode)->toBe(0);
        Expect::that(\is_file($project->path('markers/provisioned.log')))->toBeFalse();
        Expect::that(\is_file($project->path('markers/cleaned.log')))->toBeFalse();
    }

    #[Test]
    #[DataSet('runnerWorkerCounts')]
    public function filteredEmptyPlansDoNotStartWorkerInfrastructure(int $workers): void
    {
        $project = $this->writeProject('empty-plan-' . $workers, workers: $workers);
        $result = GreenlightCli::run($project->directory, ['run', '--filter=MissingTest', '--no-ansi']);

        Expect::that($result->exitCode)
            ->because('a filtered empty plan MUST use the no-tests exit')
            ->toBe(1);
        Expect::that($result->output())->toContain('Greenlight found no tests.');
        Expect::that(\is_file($project->path('markers/provisioned.log')))
            ->because('a filtered empty plan MUST NOT provision integration fixtures')
            ->toBeFalse();
        Expect::that(\is_file($project->path('markers/bootstrapped.log')))
            ->because('a filtered empty plan MUST NOT bootstrap worker plugins')
            ->toBeFalse();
        Expect::that(\is_file($project->path('markers/cleaned.log')))
            ->because('an unprovisioned fixture graph has no cleanup to run')
            ->toBeFalse();
    }

    #[Test]
    public function repeatProvisionsAndCleansOneFixtureGraphPerIteration(): void
    {
        $project = $this->writeProject('repeat', workers: 1);
        $result = GreenlightCli::run($project->directory, ['run', '--repeat=2', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that($this->lines($project->path('markers/provisioned.log')))->toHaveCount(2);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned', 'cleaned']);
    }

    #[Test]
    #[DataSet('runnerWorkerCounts')]
    public function mutablePluginPropertiesCannotCrossTheOrchestratorWorkerSeam(int $workers): void
    {
        $project = $this->writePluginSeamProject('plugin-seam-' . $workers, $workers);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)
            ->because('plugin seam run failed: ' . $result->output())
            ->toBe(0);
        Expect::that($result->output())->toContain('2 tests, 2 passed');
        Expect::that($this->lines($project->path('markers/constructed.log')))
            ->because('one orchestrator instance and one instance for each physical worker MUST be constructed')
            ->toHaveCount($workers + 1);
    }

    #[Test]
    public function repeatIterationsConstructFreshPluginInstances(): void
    {
        $project = $this->writePluginSeamProject('plugin-seam-repeat', 1);
        $result = GreenlightCli::run($project->directory, ['run', '--repeat=2', '--reporter=plain']);

        Expect::that($result->exitCode)
            ->because('plugin seam repeat run failed: ' . $result->output())
            ->toBe(0);
        Expect::that($this->lines($project->path('markers/constructed.log')))
            ->because('each repeat iteration MUST construct one orchestrator instance and one worker instance')
            ->toHaveCount(4);
    }

    #[Test]
    public function partialProvisioningFailureCleansAndFailsBeforeTestsStart(): void
    {
        $project = $this->writeProject('provisioning-failure', workers: 2, failProvisioning: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->toContain('intentional fixture provisioning failure')
            ->not()->toContain('tests,');
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    #[DataSet('runnerWorkerCounts')]
    public function provisioningAndRollbackFailuresAreBothReported(int $workers): void
    {
        $project = $this->writeProject(
            'provisioning-and-rollback-failure-' . $workers,
            workers: $workers,
            failProvisioning: true,
            failCleanup: true,
        );
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->because('the provisioning failure MUST remain the primary run failure')
            ->toContain('intentional fixture provisioning failure');
        Expect::that($result->output())
            ->because('rollback failures MUST remain visible after a provisioning failure')
            ->toContain('Additionally, cleanup for integration fixture "probe" failed')
            ->toContain('intentional fixture cleanup failure')
            ->not()->toContain('tests,')
            ->not()->toContain('fixture-secret');
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    #[DataSet('runnerWorkerCounts')]
    public function cleanupFailureFailsAnOtherwiseSuccessfulRun(int $workers): void
    {
        $project = $this->writeProject('cleanup-failure-' . $workers, workers: $workers, failCleanup: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->toContain('Integration fixture teardown failed.')
            ->toContain('intentional fixture cleanup failure')
            ->not()->toContain('fixture-secret');
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function workerCrashDoesNotOrphanOrchestratorOwnedResources(): void
    {
        $project = $this->writeProject('worker-crash', workers: 2, crashing: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('crashed during this test');
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function partialWorkerStartupFailsBeforeAssignmentAndTearsDown(): void
    {
        $project = $this->writeProject('bootstrap-failure', workers: 2, failBootstrapChannel: 2);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())->toContain('intentional worker bootstrap failure');
        Expect::that(\is_file($project->path('markers/executed.log')))->toBeFalse();
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function inProcessWorkerBootstrapFailureIsReportedAndTearsDown(): void
    {
        $project = $this->writeProject('in-process-bootstrap-failure', workers: 1, failBootstrapChannel: 1);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->toContain('intentional worker bootstrap failure')
            ->toContain('reported a fatal Greenlight error');
        Expect::that(\is_file($project->path('markers/executed.log')))->toBeFalse();
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    #[DataSet('workerModes')]
    public function bootstrapAndCleanupFailuresAreBothReported(
        int $workers,
        int $failingChannel,
    ): void {
        $project = $this->writeProject(
            'bootstrap-and-cleanup-failure-' . $workers,
            workers: $workers,
            failCleanup: true,
            failBootstrapChannel: $failingChannel,
        );
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->output())
            ->because('the worker bootstrap failure MUST remain the primary run failure')
            ->toContain('intentional worker bootstrap failure');
        Expect::that($result->output())
            ->because('fixture cleanup failures MUST remain visible after a run failure')
            ->toContain('Additionally, cleanup for integration fixture "probe" failed')
            ->toContain('intentional fixture cleanup failure')
            ->not()->toContain('fixture-secret');
        Expect::that(\is_file($project->path('markers/executed.log')))->toBeFalse();
        Expect::that($this->matches($project->path('markers/resource-*')))->toBe([]);
        Expect::that($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    /**
     * @return iterable<string, array{positive-int, positive-int}>
     */
    public static function workerModes(): iterable
    {
        yield 'in-process' => [1, 1];
        yield 'parallel' => [2, 2];
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function runnerWorkerCounts(): iterable
    {
        yield 'in-process runner' => [1];
        yield 'parallel runner' => [2];
    }

    /**
     * @return list<string>
     */
    private function lines(string $file): array
    {
        $lines = \file($file, \FILE_IGNORE_NEW_LINES);

        return \is_array($lines) ? $lines : [];
    }

    /**
     * @return list<string>
     */
    private function matches(string $pattern): array
    {
        $matches = \glob($pattern);

        return \is_array($matches) ? $matches : [];
    }

    private function writeProject(
        string $name,
        int $workers,
        bool $failing = false,
        bool $failProvisioning = false,
        bool $failCleanup = false,
        bool $crashing = false,
        ?int $failBootstrapChannel = null,
    ): AcceptanceProject {
        $project = AcceptanceProject::create($this->tempDirectory, 'integration-fixtures-' . $name);
        $project->writeFile('markers/.gitkeep', '');
        $markerDirectory = \var_export($project->path('markers'), true);

        $testTemplate = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace IntegrationFixtureProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Test\TestChannel;
            use Greenlight\Expect\Expect;
            use Greenlight\IntegrationFixture\IntegrationResources;
            use Greenlight\Tests\Fixture\Plugins\IntegrationProbeService;

            final class %sTest
            {
                public function __construct(
                    private readonly IntegrationProbeService $probe,
                    private readonly IntegrationResources $resources,
                    private readonly TestChannel $channel,
                ) {}

                #[Test]
                public function usesProvisionedInfrastructure(): void
                {
                    \file_put_contents(%s . '/executed.log', "executed\n", \FILE_APPEND);

                    Expect::that($this->probe->channel)->toBe($this->channel->number);
                    Expect::that($this->probe->secret)->toBe('fixture-secret-' . $this->channel->number);
                    Expect::that(\file_get_contents($this->probe->resourceFile))->toBe('ready');
                    Expect::that($this->resources->fixture('probe')->int('channel'))
                        ->toBe($this->channel->number);

                    %s
                }
            }
            PHP;

        $files = [];

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $class) {
            $failure = $failing
                ? "throw new \\RuntimeException('intentional failure');"
                : ($crashing && $class === 'Alpha' ? 'exit(9);' : '');
            $relative = 'tests/' . $class . 'Test.php';
            $project->writeFile($relative, \sprintf($testTemplate, $class, $markerDirectory, $failure));
            $files[] = $relative;
        }

        $requires = \implode("\n", \array_map(
            static fn(string $file): string => "require_once __DIR__ . '/{$file}';",
            $files,
        ));
        $failProvisioningValue = $failProvisioning ? 'true' : 'false';
        $failCleanupValue = $failCleanup ? 'true' : 'false';
        $failBootstrapChannelValue = $failBootstrapChannel === null ? 'null' : (string) $failBootstrapChannel;

        $project->writeFile('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Config\\GreenlightConfig;
            use Greenlight\\Tests\\Fixture\\Plugins\\IntegrationProbePlugin;

            {$requires}

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers({$workers})
                ->plugins(
                    static fn(): IntegrationProbePlugin => new IntegrationProbePlugin(
                        {$markerDirectory},
                        failProvisioning: {$failProvisioningValue},
                        failCleanup: {$failCleanupValue},
                        failBootstrapChannel: {$failBootstrapChannelValue},
                    ),
                );
            PHP);

        return $project;
    }

    private function writePluginSeamProject(string $name, int $workers): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'integration-fixtures-' . $name);
        $project->writeFile('markers/.gitkeep', '');
        $markerDirectory = \var_export($project->path('markers'), true);

        $test = <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace PluginSeamProbe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Tests\Fixture\Plugins\PluginSeamProbe;

            final readonly class %sTest
            {
                public function __construct(private PluginSeamProbe $probe) {}

                #[Test]
                public function usesOnlyTheSupportedTransferMechanism(): void
                {
                    Expect::that($this->probe->workerProperty)
                        ->because('mutable plugin properties MUST not cross the orchestrator and worker seam')
                        ->toBe('worker-fresh');
                    Expect::that($this->probe->integrationResource)
                        ->because('integration resources MUST transfer orchestrator fixture data to workers')
                        ->toBe('integration-resource');
                }
            }
            PHP;

        $files = [];

        foreach (['Alpha', 'Bravo'] as $class) {
            $relative = 'tests/' . $class . 'Test.php';
            $project->writeFile($relative, \sprintf($test, $class));
            $files[] = $relative;
        }

        $requires = \implode("\n", \array_map(
            static fn(string $file): string => "require_once __DIR__ . '/{$file}';",
            $files,
        ));

        $project->writeFile('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Tests\Fixture\Plugins\PluginSeamProbePlugin;

            {$requires}

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers({$workers})
                ->plugins(
                    static fn(): PluginSeamProbePlugin => new PluginSeamProbePlugin({$markerDirectory}),
                );
            PHP);

        return $project;
    }
}
