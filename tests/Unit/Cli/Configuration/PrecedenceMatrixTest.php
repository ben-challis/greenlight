<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Configuration;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Cli\Configuration\CoverageOverrides;
use Greenlight\Cli\Configuration\ExecutionOverrides;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

/**
 * Covers each option that a command-line value can replace. It tests default,
 * configuration, command-line, and combined values. Command-line values MUST
 * have priority in the combined case.
 */
final class PrecedenceMatrixTest
{
    #[Test]
    public function workersPrecedence(): void
    {
        Expect::that($this->resolve()->workers->count->isAuto())->because('worker options use the required precedence')->toBeTrue();
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->workers(count: 4))->workers->count->fixed,
        )->because('worker options use the required precedence')->toBe(4);
        Expect::that($this->resolve(cli: new CliOverrides(execution: new ExecutionOverrides(workers: WorkerCount::exactly(2))))->workers->count->fixed)->because('worker options use the required precedence')->toBe(2);
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->workers(count: 4), cli: new CliOverrides(execution: new ExecutionOverrides(workers: WorkerCount::exactly(2))))->workers->count->fixed,
        )->because('worker options use the required precedence')->toBe(2);
    }

    #[Test]
    public function stopAfterFailuresPrecedence(): void
    {
        Expect::that($this->resolve()->execution->stopAfterFailures)->because('stop-limit options use the required precedence')->toBe(null);
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->failFast())->execution->stopAfterFailures,
        )->because('stop-limit options use the required precedence')->toBe(1);
        Expect::that($this->resolve(cli: new CliOverrides(execution: new ExecutionOverrides(stopAfterFailures: 3)))->execution->stopAfterFailures)->because('stop-limit options use the required precedence')->toBe(3);
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->failFast(), cli: new CliOverrides(execution: new ExecutionOverrides(stopAfterFailures: 3)))->execution->stopAfterFailures,
        )->because('stop-limit options use the required precedence')->toBe(3);
    }

    #[Test]
    public function randomOrderAndSeedPrecedence(): void
    {
        $default = $this->resolve();
        Expect::that($default->order->isRandomized())->because('random order and seed options use the required precedence')->toBe(false);
        Expect::that($default->order->seed)->because('random order and seed options use the required precedence')->toBe(null);

        $configOnly = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(seed: 11));
        Expect::that($configOnly->order->isRandomized())->because('random order and seed options use the required precedence')->toBe(true);
        Expect::that($configOnly->order->seed)->because('random order and seed options use the required precedence')->toBe(11);

        $cliOnly = $this->resolve(cli: new CliOverrides(seed: 22));
        Expect::that($cliOnly->order->isRandomized())->because('random order and seed options use the required precedence')->toBe(true);
        Expect::that($cliOnly->order->seed)->because('random order and seed options use the required precedence')->toBe(22);

        $both = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(seed: 11), cli: new CliOverrides(seed: 22));
        Expect::that($both->order->isRandomized())->because('random order and seed options use the required precedence')->toBe(true);
        Expect::that($both->order->seed)->because('random order and seed options use the required precedence')->toBe(22);
    }

    #[Test]
    public function randomizeOrderWithoutASeedChoosesOneAtResolveTime(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder());

        Expect::that($resolved->order->isRandomized())->because('randomize order without a seed chooses one at resolve time')->toBeTrue();
        Expect::that($resolved->order->seed)->because('randomize order without a seed chooses one at resolve time')->not()->toBeNull();
    }

    #[Test]
    public function anExplicitCommandLineSeedStillOverridesAnAutoChosenOne(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(), cli: new CliOverrides(seed: 77));

        Expect::that($resolved->order->seed)->because('an explicit command line seed still overrides an auto chosen one')->toBe(77);
    }

    #[Test]
    public function groupsPrecedence(): void
    {
        Expect::that($this->resolve()->selection->include->groups)->because('group options use the required precedence')->toBe([]);
        Expect::that($this->resolve(cli: new CliOverrides(selection: new TestSelection(include: new TestInclusions(groups: ['slow']))))->selection->include->groups)->because('group options use the required precedence')->toBe(['slow']);
    }

    #[Test]
    public function artifactDirectoryPrecedence(): void
    {
        Expect::that($this->resolve()->execution->artifacts->directory)->because('artifact directory options use the required precedence')->toBe('build/greenlight-artifacts');
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c
                ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('build/config-evidence')))
                ->execution->artifacts->directory,
        )->because('artifact directory options use the required precedence')->toBe('build/config-evidence');
        Expect::that(
            $this->resolve(cli: new CliOverrides(execution: new ExecutionOverrides(artifactsDirectory: 'build/cli-evidence')))->execution->artifacts->directory,
        )->because('artifact directory options use the required precedence')->toBe('build/cli-evidence');
        Expect::that(
            $this->resolve(
                config: static fn(GreenlightConfig $c) => $c
                    ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('build/config-evidence')),
                cli: new CliOverrides(execution: new ExecutionOverrides(artifactsDirectory: 'build/cli-evidence')),
            )->execution->artifacts->directory,
        )->because('artifact directory options use the required precedence')->toBe('build/cli-evidence');
    }

    #[Test]
    public function resourceLimitPrecedenceMergesByName(): void
    {
        $resolved = $this->resolve(
            config: static fn(GreenlightConfig $c) => $c->resourceLimit('postgres', 4)->resourceLimit('redis', 2),
            cli: new CliOverrides(execution: new ExecutionOverrides(resourceLimits: ['postgres' => 1])),
        );

        Expect::that($resolved->workers->resourceLimits)->because('resource limit precedence merges by name')->toBe(['postgres' => 1, 'redis' => 2]);
    }

    #[Test]
    public function coverageGatePrecedence(): void
    {
        $resolved = $this->resolve(
            config: static fn(GreenlightConfig $c) => $c->coverage(static fn(CoverageBuilder $coverage) => $coverage
                ->minimumPercentage(90.0)
                ->maximumUncoveredLines(10)),
            cli: new CliOverrides(coverage: new CoverageOverrides(95.5, 2, true)),
        );

        Expect::that($resolved->coverage?->minimumPercentage)
            ->because('the command-line minimum coverage MUST replace the configured value')
            ->toBe(95.5);
        Expect::that($resolved->coverage?->maximumUncoveredLines)
            ->because('the command-line uncovered-line maximum MUST replace the configured value')
            ->toBe(2);
        Expect::that($resolved->coverage?->requireDriver)
            ->because('the command line can require a coverage driver')
            ->toBeTrue();
    }

    #[Test]
    public function aCoverageGateFlagEnablesCoverage(): void
    {
        $resolved = $this->resolve(cli: new CliOverrides(coverage: new CoverageOverrides(minimumPercentage: 80.0)));

        Expect::that($resolved->coverage?->minimumPercentage)
            ->because('a command-line coverage gate needs coverage collection')
            ->toBe(80.0);
    }

    #[Test]
    public function settingsWithoutFlagsAlwaysComeFromTheConfigFile(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c
            ->paths(['tests/Only']), cli: new CliOverrides(
                execution: new ExecutionOverrides(workers: WorkerCount::auto(), stopAfterFailures: 1),
                selection: new TestSelection(include: new TestInclusions(groups: ['g'])),
                seed: 1,
            ));

        Expect::that($resolved->discovery->paths)->because('settings without flags use the configuration file')->toBe(['tests/Only']);
    }

    /**
     * @param (callable(GreenlightConfig): GreenlightConfig)|null $config
     */
    private function resolve(?callable $config = null, ?CliOverrides $cli = null): ResolvedConfiguration
    {
        $builder = GreenlightConfig::create();

        if ($config !== null) {
            $builder = $config($builder);
        }

        return ConfigurationResolver::resolve($builder->build(), $cli ?? new CliOverrides());
    }
}
