<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class IntegrationFixtureRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function parallelWorkersReceiveChannelResourcesAndCleanupAfterRecycling(): void
    {
        $project = $this->writeProject('parallel', workers: 2, recycleAfterTests: 1);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        $bootstrapped = $this->lines($project->path('markers/bootstrapped.log'));
        $resources = $this->matches($project->path('markers/resource-*'));

        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('4 tests, 4 passed')
            ->not()->toContain('fixture-secret')
            ->and(\count($bootstrapped))->toBeGreaterThan(2)
            ->and($resources)->toBe([])
            ->and($this->lines($project->path('markers/provisioned.log')))->toHaveCount(1)
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function inProcessFailuresStillTearDownTheFixture(): void
    {
        $project = $this->writeProject('failure', workers: 1, failing: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('intentional failure')
            ->not()->toContain('fixture-secret')
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function inspectionCommandsDoNotProvisionInfrastructure(): void
    {
        $project = $this->writeProject('listing', workers: 2);
        $listTests = GreenlightCli::run($project->directory, ['list-tests']);
        $listGroups = GreenlightCli::run($project->directory, ['run', '--list-groups']);
        $listSuites = GreenlightCli::run($project->directory, ['run', '--list-suites']);
        $dryRun = GreenlightCli::run($project->directory, ['run', '--dry-run']);

        Expect::that($listTests->exitCode)->toBe(0)
            ->and($listGroups->exitCode)->toBe(0)
            ->and($listSuites->exitCode)->toBe(0)
            ->and($dryRun->exitCode)->toBe(0)
            ->and(\is_file($project->path('markers/provisioned.log')))->toBeFalse()
            ->and(\is_file($project->path('markers/cleaned.log')))->toBeFalse();
    }

    #[Test]
    public function repeatProvisionsAndCleansOneFixtureGraphPerIteration(): void
    {
        $project = $this->writeProject('repeat', workers: 1);
        $result = GreenlightCli::run($project->directory, ['run', '--repeat=2', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(0)
            ->and($this->lines($project->path('markers/provisioned.log')))->toHaveCount(2)
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned', 'cleaned']);
    }

    #[Test]
    public function partialProvisioningFailureCleansAndFailsBeforeTestsStart(): void
    {
        $project = $this->writeProject('provisioning-failure', workers: 2, failProvisioning: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('intentional fixture provisioning failure')
            ->not()->toContain('tests,')
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    #[DataSet('workerCounts')]
    public function provisioningAndRollbackFailuresAreBothReported(int $workers): void
    {
        $project = $this->writeProject(
            'provisioning-and-rollback-failure-' . $workers,
            workers: $workers,
            failProvisioning: true,
            failCleanup: true,
        );
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())
            ->because('the provisioning failure MUST remain the primary run failure')
            ->toContain('intentional fixture provisioning failure')
            ->and($result->output())
            ->because('rollback failures MUST remain visible after a provisioning failure')
            ->toContain('Additionally, cleanup for integration fixture "probe" failed')
            ->toContain('intentional fixture cleanup failure')
            ->not()->toContain('tests,')
            ->not()->toContain('fixture-secret')
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    #[DataSet('workerCounts')]
    public function transportValidationAndRollbackFailuresAreBothReported(int $workers): void
    {
        $project = $this->writeTransportFailureProject($workers);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())
            ->because('the transport failure MUST remain the primary provisioning failure')
            ->toContain('Integration fixture "resource catalog" failed to provision')
            ->toContain('exceed the 1 MiB transport limit')
            ->and($result->output())
            ->because('rollback failures MUST remain visible after transport validation fails')
            ->toContain('Additionally, cleanup for integration fixture "oversized" failed')
            ->toContain('intentional transport rollback failure')
            ->not()->toContain('fixture-secret')
            ->and(\is_file($project->path('markers/executed.log')))->toBeFalse()
            ->and($this->lines($project->path('markers/rolled-back.log')))->toBe(['rolled back']);
    }

    #[Test]
    #[DataSet('workerCounts')]
    public function cleanupFailureFailsAnOtherwiseSuccessfulRun(int $workers): void
    {
        $project = $this->writeProject('cleanup-failure-' . $workers, workers: $workers, failCleanup: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('Integration fixture teardown failed.')
            ->and($result->output())->toContain('intentional fixture cleanup failure')
            ->not()->toContain('fixture-secret')
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function workerCrashDoesNotOrphanOrchestratorOwnedResources(): void
    {
        $project = $this->writeProject('worker-crash', workers: 2, crashing: true);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('crashed during this test')
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function partialWorkerStartupFailsBeforeAssignmentAndTearsDown(): void
    {
        $project = $this->writeProject('bootstrap-failure', workers: 2, failBootstrapChannel: 2);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('intentional worker bootstrap failure')
            ->and(\is_file($project->path('markers/executed.log')))->toBeFalse()
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
    }

    #[Test]
    public function inProcessWorkerBootstrapFailureIsReportedAndTearsDown(): void
    {
        $project = $this->writeProject('in-process-bootstrap-failure', workers: 1, failBootstrapChannel: 1);
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('intentional worker bootstrap failure')
            ->and($result->output())->toContain('reported a fatal Greenlight error')
            ->and(\is_file($project->path('markers/executed.log')))->toBeFalse()
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
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

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())
            ->because('the worker bootstrap failure MUST remain the primary run failure')
            ->toContain('intentional worker bootstrap failure')
            ->and($result->output())
            ->because('fixture cleanup failures MUST remain visible after a run failure')
            ->toContain('Additionally, cleanup for integration fixture "probe" failed')
            ->toContain('intentional fixture cleanup failure')
            ->not()->toContain('fixture-secret')
            ->and(\is_file($project->path('markers/executed.log')))->toBeFalse()
            ->and($this->matches($project->path('markers/resource-*')))->toBe([])
            ->and($this->lines($project->path('markers/cleaned.log')))->toBe(['cleaned']);
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
     * @return iterable<string, array{positive-int}>
     */
    public static function workerCounts(): iterable
    {
        yield 'in-process' => [1];
        yield 'parallel' => [2];
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

    private function writeTransportFailureProject(int $workers): AcceptanceProject
    {
        $project = AcceptanceProject::create(
            $this->tempDirectory,
            'integration-fixtures-transport-failure-' . $workers,
        );
        $project->writeFile('markers/.gitkeep', '');
        $markerDirectory = \var_export($project->path('markers'), true);

        $project->writeFile('tests/TransportProbeTest.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Attribute\\Test;

            final class TransportProbeTest
            {
                #[Test]
                public function executes(): void
                {
                    \\file_put_contents({$markerDirectory} . '/executed.log', "executed\\n");
                }
            }
            PHP);

        $project->writeFile('greenlight.php', <<<PHP
            <?php

            declare(strict_types=1);

            use Greenlight\\Config\\GreenlightConfig;
            use Greenlight\\Doubles\\Fake;
            use Greenlight\\Harness\\FixtureResource;
            use Greenlight\\Plugin\\IntegrationFixtureContext;
            use Greenlight\\Plugin\\IntegrationFixtureDefinition;
            use Greenlight\\Plugin\\IntegrationFixtureProvider;

            require_once __DIR__ . '/tests/TransportProbeTest.php';

            \$provider = new readonly class ({$markerDirectory}) implements Fake, IntegrationFixtureProvider {
                public function __construct(private string \$markerDirectory) {}

                #[\\Override]
                public function integrationFixtures(): array
                {
                    return [
                        new IntegrationFixtureDefinition(
                            'oversized',
                            function (IntegrationFixtureContext \$context): void {
                                \$context->defer(function (): void {
                                    \\file_put_contents(
                                        \$this->markerDirectory . '/rolled-back.log',
                                        "rolled back\\n",
                                    );

                                    throw new RuntimeException('intentional transport rollback failure');
                                });
                                \$context->expose(FixtureResource::from(
                                    values: ['payload' => \\str_repeat('x', 1_048_576)],
                                    secrets: ['token' => 'fixture-secret'],
                                ));
                            },
                        ),
                    ];
                }
            };

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->workers({$workers})
                ->plugins(\$provider);
            PHP);

        return $project;
    }

    private function writeProject(
        string $name,
        int $workers,
        ?int $recycleAfterTests = null,
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
            use Greenlight\Core\Test\TestChannel;
            use Greenlight\Expect\Expect;
            use Greenlight\Harness\IntegrationResources;
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

                    Expect::that($this->probe->channel)->toBe($this->channel->number)
                        ->and($this->probe->secret)->toBe('fixture-secret-' . $this->channel->number)
                        ->and(\file_get_contents($this->probe->resourceFile))->toBe('ready')
                        ->and($this->resources->fixture('probe')->int('channel'))->toBe($this->channel->number);

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
        $recycle = $recycleAfterTests === null
            ? ''
            : ', recycleAfterTests: ' . $recycleAfterTests;
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
                ->workers({$workers}{$recycle})
                ->plugins(new IntegrationProbePlugin(
                    {$markerDirectory},
                    failProvisioning: {$failProvisioningValue},
                    failCleanup: {$failCleanupValue},
                    failBootstrapChannel: {$failBootstrapChannelValue},
                ));
            PHP);

        return $project;
    }
}
