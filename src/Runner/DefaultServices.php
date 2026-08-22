<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestChannel;

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
        ?string $generatedCodeDirectory = null,
        ?string $temporaryDirectory = null,
    ): HarnessRegistry {
        Expect::install($plugins->ofType(ExpectationExtension::class));

        $registry = new HarnessRegistry([
            new ServiceDefinition(Doubles::class, Scope::PerTest, static fn(): Doubles => new Doubles($generatedCodeDirectory)),
            new ServiceDefinition(TemporaryDirectory::class, Scope::PerTest, static fn(): TemporaryDirectory => new TemporaryDirectory($temporaryDirectory)),
            new ServiceDefinition(Autoloaders::class, Scope::PerTest, static fn(): Autoloaders => new Autoloaders()),
            new ServiceDefinition(EnvironmentVariables::class, Scope::PerTest, static fn(): EnvironmentVariables => new EnvironmentVariables()),
            new ServiceDefinition(StreamWrappers::class, Scope::PerTest, static fn(): StreamWrappers => new StreamWrappers()),
            new ServiceDefinition(IntegrationResources::class, Scope::PerWorker, static fn(): IntegrationResources => $integrationResources),
            new ServiceDefinition(
                TestChannel::class,
                Scope::PerWorker,
                static fn(): TestChannel => new TestChannel(ChannelEnvironment::parse(\getenv('GREENLIGHT_CHANNEL')) ?? 1),
            ),
        ]);

        foreach ($plugins->harnessServices() as $definition) {
            $registry->register($definition);
        }

        return $registry;
    }
}
