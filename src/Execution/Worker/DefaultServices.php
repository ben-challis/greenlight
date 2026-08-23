<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Doubles\Doubles;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationExtension;
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

    /**
     * @return list<ServiceDefinition>
     */
    public static function definitions(
        PluginRegistry $plugins = new PluginRegistry(),
        IntegrationResources $integrationResources = new IntegrationResources(),
        ?string $generatedCodeDirectory = null,
        ?string $temporaryDirectory = null,
    ): array {
        Expect::install($plugins->ofType(ExpectationExtension::class));

        return [
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
            ...$plugins->harnessServices(),
        ];
    }
}
