<?php

declare(strict_types=1);

namespace Greenlight\Execution\Worker;

use Greenlight\Doubles\Doubles;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\Prioritized;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestChannel;

/**
 * Supplies the standard Greenlight harness services. `GREENLIGHT_CHANNEL`
 * supplies the channel when the worker does not supply it directly.
 *
 * @internal
 */
final readonly class StandardHarnessPlugin implements HarnessProvider, Prioritized
{
    public function __construct(
        private IntegrationResources $integrationResources = new IntegrationResources(),
        private ?TestChannel $channel = null,
        private ?string $generatedCodeDirectory = null,
        private ?string $temporaryDirectory = null,
    ) {}

    #[\Override]
    public function priority(): int
    {
        return \PHP_INT_MIN;
    }

    /** @return list<ServiceDefinition> */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(Doubles::class, Scope::PerTest, fn(): Doubles => new Doubles($this->generatedCodeDirectory)),
            new ServiceDefinition(TemporaryDirectory::class, Scope::PerTest, fn(): TemporaryDirectory => new TemporaryDirectory($this->temporaryDirectory)),
            new ServiceDefinition(Autoloaders::class, Scope::PerTest, static fn(): Autoloaders => new Autoloaders()),
            new ServiceDefinition(EnvironmentVariables::class, Scope::PerTest, static fn(): EnvironmentVariables => new EnvironmentVariables()),
            new ServiceDefinition(StreamWrappers::class, Scope::PerTest, static fn(): StreamWrappers => new StreamWrappers()),
            new ServiceDefinition(IntegrationResources::class, Scope::PerWorker, fn(): IntegrationResources => $this->integrationResources),
            new ServiceDefinition(
                TestChannel::class,
                Scope::PerWorker,
                fn(): TestChannel => $this->channel
                    ?? new TestChannel(ChannelEnvironment::parse(\getenv('GREENLIGHT_CHANNEL')) ?? 1),
            ),
        ];
    }
}
