<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Execution\Plugin\OrchestratorPluginRuntime;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\IntegrationFixture\IntegrationFixtureManager;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Tests\Fixture\Plugins\FakeIntegrationFixtureProvider;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class OrchestratorPluginRuntimeFixtureTest
{
    #[Test]
    public function fixtureDefinitionsInvokeProvidersInRegistrationOrder(): void
    {
        $first = new IntegrationFixtureDefinition('first', static function (): void {});
        $second = new IntegrationFixtureDefinition('second', static function (): void {});
        $runtime = OrchestratorPluginRuntime::fromPlugins([
            new FakeIntegrationFixtureProvider([$first]),
            new FakeIntegrationFixtureProvider([$second]),
        ], new CollectingEventSink());

        Expect::that([...$runtime->fixtureDefinitions()])
            ->because('the runtime MUST retain integration fixture provider order')
            ->toBe([$first, $second]);
    }

    #[Test]
    public function providerFailuresAreReportedAsIntegrationFixtureErrors(): void
    {
        $failure = new \RuntimeException('provider exploded');
        $provider = new readonly class ($failure) implements IntegrationFixtureProvider {
            public function __construct(private \RuntimeException $failure) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                throw $this->failure;
            }
        };

        $runtime = OrchestratorPluginRuntime::fromPlugins([$provider], new CollectingEventSink());

        Expect::that(fn(): array => [...$runtime->fixtureDefinitions()])
            ->toThrow(static function (IntegrationFixtureError $error) use ($failure, $provider): void {
                Expect::that($error->getMessage())->toBe(\sprintf(
                    'Integration fixture provider "%s" failed: provider exploded.',
                    $provider::class,
                ));
                Expect::that($error->getPrevious())->toBe($failure);
            });
    }

    #[Test]
    public function invalidProviderEntriesAreRejectedAtThePluginSeam(): void
    {
        $provider = new FakeIntegrationFixtureProvider([]);
        new \ReflectionProperty($provider, 'definitions')->setValue($provider, [new \stdClass()]);

        $runtime = OrchestratorPluginRuntime::fromPlugins([$provider], new CollectingEventSink());

        Expect::that(fn(): array => [...$runtime->fixtureDefinitions()])
            ->because('integration fixture providers MUST return fixture definitions')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture provider "'
                    . FakeIntegrationFixtureProvider::class
                    . '" returned stdClass. It MUST return IntegrationFixtureDefinition instances.',
            );
    }

    #[Test]
    public function duplicateIdsStopBeforeLaterProvidersRun(): void
    {
        $laterFailure = new \RuntimeException('later provider ran');
        $laterProvider = new readonly class ($laterFailure) implements IntegrationFixtureProvider {
            public function __construct(private \RuntimeException $failure) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                throw $this->failure;
            }
        };
        $runtime = OrchestratorPluginRuntime::fromPlugins([
            new FakeIntegrationFixtureProvider([
                new IntegrationFixtureDefinition('database', static function (): void {}),
            ]),
            new FakeIntegrationFixtureProvider([
                new IntegrationFixtureDefinition('database', static function (): void {}),
            ]),
            $laterProvider,
        ], new CollectingEventSink());

        Expect::that(fn() => IntegrationFixtureManager::provision(
            $runtime->fixtureDefinitions(),
            'run-duplicate',
            1,
            1,
            null,
        ))
            ->because('duplicate IDs MUST stop provider iteration before a later provider runs')
            ->toThrow(
                IntegrationFixtureError::class,
                message: 'Integration fixture "database" is declared more than once.',
            );
    }
}
