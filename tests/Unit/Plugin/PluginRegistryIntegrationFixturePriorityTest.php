<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\Prioritized;

final readonly class PluginRegistryIntegrationFixturePriorityTest
{
    #[Test]
    public function integrationFixtureProvidersKeepStablePriorityOrder(): void
    {
        $late = $this->prioritizedProvider(10);
        $default = $this->provider();
        $samePriority = $this->prioritizedProvider(0);
        $early = $this->prioritizedProvider(-10);

        $providers = new PluginRegistry([
            $late,
            $default,
            $samePriority,
            $early,
        ])->integrationFixtureProviders();

        Expect::that($providers)
            ->because('integration fixture providers MUST keep stable plugin priority order')
            ->toBe([
                $early,
                $default,
                $samePriority,
                $late,
            ]);
    }

    private function provider(): IntegrationFixtureProvider
    {
        return new readonly class implements Fake, IntegrationFixtureProvider {
            #[\Override]
            public function integrationFixtures(): array
            {
                return [];
            }
        };
    }

    private function prioritizedProvider(int $priority): IntegrationFixtureProvider
    {
        return new readonly class ($priority) implements Fake, IntegrationFixtureProvider, Prioritized {
            public function __construct(private int $priority) {}

            #[\Override]
            public function integrationFixtures(): array
            {
                return [];
            }

            #[\Override]
            public function priority(): int
            {
                return $this->priority;
            }
        };
    }
}
