<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\Configuration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\WorkerCount;
use Greenlight\Tests\Support\Check;

/**
 * The precedence chain is fixed: built-in defaults, overridden by the config
 * file, overridden by command-line flags. Every command-line-overridable
 * setting is exercised in all four combinations: neither set, config only,
 * command line only, and both (where the command line must win).
 */
final class PrecedenceMatrixTest
{
    #[Test]
    public function workersPrecedence(): void
    {
        Check::true($this->resolve()->workers->isAuto(), 'default workers to be auto');
        Check::same(
            4,
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->workers(count: 4))->workers->fixed,
            'config workers to override the default',
        );
        Check::same(
            2,
            $this->resolve(cli: new CliOverrides(workers: WorkerCount::exactly(2)))->workers->fixed,
            'command-line workers to override the default',
        );
        Check::same(
            2,
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->workers(count: 4), cli: new CliOverrides(workers: WorkerCount::exactly(2)))->workers->fixed,
            'command-line workers to override the config file',
        );
    }

    #[Test]
    public function stopAfterFailuresPrecedence(): void
    {
        Check::same(null, $this->resolve()->stopAfterFailures, 'default is to never stop early');
        Check::same(
            1,
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->failFast())->stopAfterFailures,
            'config failFast to override the default',
        );
        Check::same(
            3,
            $this->resolve(cli: new CliOverrides(stopAfterFailures: 3))->stopAfterFailures,
            'command-line bail to override the default',
        );
        Check::same(
            3,
            $this->resolve(config: static fn(GreenlightConfig $c) => $c->failFast(), cli: new CliOverrides(stopAfterFailures: 3))->stopAfterFailures,
            'command-line bail to override config failFast',
        );
    }

    #[Test]
    public function randomOrderAndSeedPrecedence(): void
    {
        $default = $this->resolve();
        Check::same(false, $default->randomizeOrder, 'default order to be declared');
        Check::same(null, $default->randomSeed, 'default seed');

        $configOnly = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(seed: 11));
        Check::same(true, $configOnly->randomizeOrder, 'config randomizeOrder to override the default');
        Check::same(11, $configOnly->randomSeed, 'config seed to override the default');

        $cliOnly = $this->resolve(cli: new CliOverrides(seed: 22));
        Check::same(true, $cliOnly->randomizeOrder, 'a command-line seed to enable randomization');
        Check::same(22, $cliOnly->randomSeed, 'command-line seed to override the default');

        $both = $this->resolve(config: static fn(GreenlightConfig $c) => $c->randomizeOrder(seed: 11), cli: new CliOverrides(seed: 22));
        Check::same(true, $both->randomizeOrder, 'randomization to stay enabled');
        Check::same(22, $both->randomSeed, 'command-line seed to override the config seed');
    }

    #[Test]
    public function groupsPrecedence(): void
    {
        Check::same([], $this->resolve()->groups, 'default groups to be empty (no filter)');
        Check::same(
            ['slow'],
            $this->resolve(cli: new CliOverrides(groups: ['slow']))->groups,
            'command-line groups to apply',
        );
    }

    #[Test]
    public function settingsWithoutFlagsAlwaysComeFromTheConfigFile(): void
    {
        $resolved = $this->resolve(config: static fn(GreenlightConfig $c) => $c
            ->paths(['tests/Only'])
            ->workers(recycleAfterTests: 42, recycleAboveMemory: '64M'), cli: new CliOverrides(workers: WorkerCount::auto(), stopAfterFailures: 1, groups: ['g'], seed: 1));

        Check::same(['tests/Only'], $resolved->paths, 'paths to come from the config file');
        Check::same(42, $resolved->recycleAfterTests, 'recycleAfterTests to come from the config file');
        Check::same(67108864, $resolved->recycleAboveMemoryBytes, 'recycle memory to come from the config file');
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
