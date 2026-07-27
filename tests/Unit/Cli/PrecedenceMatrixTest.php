<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\Configuration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\WorkerCount;
use Greenlight\Expect\Expect;

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
        Expect::that($this->resolve()->workers->isAuto())->toBeTrue();
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->workers(count: 4))->workers->fixed,
        )->toBe(4);
        Expect::that($this->resolve(cli: new CliOverrides(workers: WorkerCount::exactly(2)))->workers->fixed)->toBe(2);
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->workers(count: 4), cli: new CliOverrides(workers: WorkerCount::exactly(2)))->workers->fixed,
        )->toBe(2);
    }

    #[Test]
    public function stopAfterFailuresPrecedence(): void
    {
        Expect::that($this->resolve()->stopAfterFailures)->toBe(null);
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->failFast())->stopAfterFailures,
        )->toBe(1);
        Expect::that($this->resolve(cli: new CliOverrides(stopAfterFailures: 3))->stopAfterFailures)->toBe(3);
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->failFast(), cli: new CliOverrides(stopAfterFailures: 3))->stopAfterFailures,
        )->toBe(3);
    }

    #[Test]
    public function randomOrderAndSeedPrecedence(): void
    {
        $default = $this->resolve();
        Expect::that($default->randomizeOrder)->toBe(false);
        Expect::that($default->randomSeed)->toBe(null);

        $configOnly = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(seed: 11));
        Expect::that($configOnly->randomizeOrder)->toBe(true);
        Expect::that($configOnly->randomSeed)->toBe(11);

        $cliOnly = $this->resolve(cli: new CliOverrides(seed: 22));
        Expect::that($cliOnly->randomizeOrder)->toBe(true);
        Expect::that($cliOnly->randomSeed)->toBe(22);

        $both = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(seed: 11), cli: new CliOverrides(seed: 22));
        Expect::that($both->randomizeOrder)->toBe(true);
        Expect::that($both->randomSeed)->toBe(22);
    }

    #[Test]
    public function randomizeOrderWithoutASeedChoosesOneAtResolveTime(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder());

        Expect::that($resolved->randomizeOrder)->toBeTrue();
        Expect::that($resolved->randomSeed)->not()->toBeNull();
    }

    #[Test]
    public function anExplicitCommandLineSeedStillOverridesAnAutoChosenOne(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(), cli: new CliOverrides(seed: 77));

        Expect::that($resolved->randomSeed)->toBe(77);
    }

    #[Test]
    public function groupsPrecedence(): void
    {
        Expect::that($this->resolve()->groups)->toBe([]);
        Expect::that($this->resolve(cli: new CliOverrides(groups: ['slow']))->groups)->toBe(['slow']);
    }

    #[Test]
    public function artifactDirectoryPrecedence(): void
    {
        Expect::that($this->resolve()->artifacts->directory)->toBe('build/greenlight-artifacts');
        Expect::that(
            $this->resolve(config: static fn(GreenlightConfig $c) => $c
                ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('build/config-evidence')))
                ->artifacts->directory,
        )->toBe('build/config-evidence');
        Expect::that(
            $this->resolve(cli: new CliOverrides(artifactsDirectory: 'build/cli-evidence'))->artifacts->directory,
        )->toBe('build/cli-evidence');
        Expect::that(
            $this->resolve(
                config: static fn(GreenlightConfig $c) => $c
                    ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('build/config-evidence')),
                cli: new CliOverrides(artifactsDirectory: 'build/cli-evidence'),
            )->artifacts->directory,
        )->toBe('build/cli-evidence');
    }

    #[Test]
    public function resourceLimitPrecedenceMergesByName(): void
    {
        $resolved = $this->resolve(
            config: static fn(GreenlightConfig $c) => $c->resourceLimit('postgres', 4)->resourceLimit('redis', 2),
            cli: new CliOverrides(resourceLimits: ['postgres' => 1]),
        );

        Expect::that($resolved->resourceLimits)->toBe(['postgres' => 1, 'redis' => 2]);
    }

    #[Test]
    public function settingsWithoutFlagsAlwaysComeFromTheConfigFile(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c
            ->paths(['tests/Only'])
            ->workers(recycleAfterTests: 42, recycleAboveMemory: '64M'), cli: new CliOverrides(workers: WorkerCount::auto(), stopAfterFailures: 1, groups: ['g'], seed: 1));

        Expect::that($resolved->paths)->toBe(['tests/Only']);
        Expect::that($resolved->recycleAfterTests)->toBe(42);
        Expect::that($resolved->recycleAboveMemoryBytes)->toBe(67108864);
    }

    /**
     * @param (callable(GreenlightConfig): GreenlightConfig)|null $config
     */
    private function resolve(?callable $config = null, ?CliOverrides $cli = null): Configuration
    {
        $builder = GreenlightConfig::create();

        if ($config !== null) {
            $builder = $config($builder);
        }

        return ConfigurationResolver::resolve($builder->build(), $cli ?? new CliOverrides());
    }
}
