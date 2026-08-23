<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Plugin\OrchestratorPluginRuntime;
use Greenlight\Expect\Expect;
use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\Prioritized;
use Greenlight\Tests\Support\CollectingEventSink;

final readonly class OrchestratorPluginRuntimePriorityTest
{
    #[Test]
    public function integrationFixtureProvidersKeepStablePriorityOrder(): void
    {
        $late = $this->prioritizedProvider('late', 10);
        $default = $this->provider('default');
        $samePriority = $this->prioritizedProvider('same-priority', 0);
        $early = $this->prioritizedProvider('early', -10);

        $runtime = OrchestratorPluginRuntime::fromPlugins([
            $late,
            $default,
            $samePriority,
            $early,
        ], new CollectingEventSink());
        $ids = \array_map(
            static fn(IntegrationFixtureDefinition $definition): string => $definition->id,
            [...$runtime->fixtureDefinitions()],
        );

        Expect::that($ids)
            ->because('integration fixture providers MUST keep stable plugin priority order')
            ->toBe([
                'early',
                'default',
                'same-priority',
                'late',
            ]);
    }

    private function provider(string $id): IntegrationFixtureProvider
    {
        return new readonly class ($id) implements Fake, IntegrationFixtureProvider {
            public function __construct(private string $id) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                return [new IntegrationFixtureDefinition($this->id, static function (): void {})];
            }
        };
    }

    private function prioritizedProvider(string $id, int $priority): IntegrationFixtureProvider
    {
        return new readonly class ($id, $priority) implements Fake, IntegrationFixtureProvider, Prioritized {
            public function __construct(private string $id, private int $priority) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                return [new IntegrationFixtureDefinition($this->id, static function (): void {})];
            }

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }
        };
    }
}
