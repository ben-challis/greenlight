<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Core\Test\TestChannel;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\IntegrationResources;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Plugin\PluginRegistry;

/**
 * GREENLIGHT_CHANNEL supplies the TestChannel value. Duplicate service types
 * are configuration errors.
 *
 * @internal
 */
final class DefaultServices
{
    #[CoverageIgnore]
    private function __construct() {}

    public static function registry(
        PluginRegistry $plugins = new PluginRegistry(),
        IntegrationResources $integrationResources = new IntegrationResources(),
    ): HarnessRegistry {
        Expect::install($plugins->ofType(ExpectationExtension::class));

        $registry = new HarnessRegistry([
            new ServiceDefinition(Doubles::class, Scope::PerTest, static fn(): Doubles => new Doubles()),
            new ServiceDefinition(TempDirectory::class, Scope::PerTest, static fn(): TempDirectory => new TempDirectory()),
            new ServiceDefinition(EnvironmentSandbox::class, Scope::PerTest, static fn(): EnvironmentSandbox => new EnvironmentSandbox()),
            new ServiceDefinition(StreamWrapperSandbox::class, Scope::PerTest, static fn(): StreamWrapperSandbox => new StreamWrapperSandbox()),
            new ServiceDefinition(IntegrationResources::class, Scope::PerRun, static fn(): IntegrationResources => $integrationResources),
            new ServiceDefinition(
                TestChannel::class,
                Scope::PerRun,
                static fn(): TestChannel => new TestChannel(ChannelEnvironment::parse(\getenv('GREENLIGHT_CHANNEL')) ?? 1),
            ),
        ]);

        foreach ($plugins->harnessServices() as $definition) {
            $registry->register($definition);
        }

        return $registry;
    }
}
