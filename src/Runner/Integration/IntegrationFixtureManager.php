<?php

declare(strict_types=1);

namespace Greenlight\Runner\Integration;

use Greenlight\Plugin\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\PluginRegistry;

/**
 * Validates and provisions the orchestrator-side integration fixture graph.
 *
 * @internal
 */
final class IntegrationFixtureManager
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param non-empty-string $runId
     * @param positive-int $configuredWorkers
     * @param positive-int $channelCount
     * @param array{int, int}|null $shard
     */
    public static function provision(
        PluginRegistry $plugins,
        string $runId,
        int $configuredWorkers,
        int $channelCount,
        ?array $shard,
    ): ProvisionedIntegrationFixtures {
        $definitions = [];

        foreach ($plugins->integrationFixtureProviders() as $provider) {
            try {
                $provided = $provider->integrationFixtures();
            } catch (\Throwable $failure) {
                throw IntegrationFixtureError::provider($provider::class, $failure);
            }

            $provided = self::validatedDefinitions($provider, $provided);

            foreach ($provided as $definition) {
                if (isset($definitions[$definition->id])) {
                    throw new IntegrationFixtureError(\sprintf(
                        'Integration fixture "%s" is declared more than once.',
                        $definition->id,
                    ));
                }

                $definitions[$definition->id] = $definition;
            }
        }

        $ordered = self::ordered($definitions);
        $session = new ProvisionedIntegrationFixtures();
        $channels = \range(1, $channelCount);

        foreach ($ordered as $definition) {
            $context = new FixtureProvisioningContext(
                $definition->id,
                $definition->dependsOn,
                $runId,
                $configuredWorkers,
                $channels,
                $shard,
                $session,
            );

            try {
                ($definition->provision)($context);
                $session->ensureExposed($definition->id);
            } catch (\Throwable $failure) {
                throw IntegrationFixtureError::provisioning($definition->id, $failure, $session->close());
            }
        }

        try {
            $session->validateTransport($channels);
        } catch (\Throwable $failure) {
            throw IntegrationFixtureError::provisioning('resource catalog', $failure, $session->close());
        }

        return $session;
    }

    /**
     * @param array<mixed> $provided
     *
     * @return list<IntegrationFixtureDefinition>
     */
    private static function validatedDefinitions(IntegrationFixtureProvider $provider, array $provided): array
    {
        $definitions = [];

        foreach ($provided as $definition) {
            if (!$definition instanceof IntegrationFixtureDefinition) {
                throw new IntegrationFixtureError(\sprintf(
                    'Integration fixture provider "%s" returned %s. '
                    . 'It MUST return IntegrationFixtureDefinition instances.',
                    $provider::class,
                    \get_debug_type($definition),
                ));
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @param array<string, IntegrationFixtureDefinition> $definitions
     *
     * @return list<IntegrationFixtureDefinition>
     */
    private static function ordered(array $definitions): array
    {
        $ordered = [];
        $state = [];
        $path = [];

        foreach ($definitions as $definition) {
            self::visit($definition, $definitions, $state, $path, $ordered);
        }

        return $ordered;
    }

    /**
     * @param array<string, IntegrationFixtureDefinition> $definitions
     * @param array<string, int> $state
     * @param list<string> $path
     * @param list<IntegrationFixtureDefinition> $ordered
     */
    private static function visit(
        IntegrationFixtureDefinition $definition,
        array $definitions,
        array &$state,
        array &$path,
        array &$ordered,
    ): void {
        if (($state[$definition->id] ?? 0) === 2) {
            return;
        }

        if (($state[$definition->id] ?? 0) === 1) {
            $cycle = [...$path, $definition->id];

            throw new IntegrationFixtureError('Integration fixture dependency cycle: ' . \implode(' -> ', $cycle) . '.');
        }

        $state[$definition->id] = 1;
        $path[] = $definition->id;

        foreach ($definition->dependsOn as $dependencyId) {
            $dependency = $definitions[$dependencyId] ?? throw new IntegrationFixtureError(\sprintf(
                'Integration fixture "%s" depends on missing fixture "%s".',
                $definition->id,
                $dependencyId,
            ));

            self::visit($dependency, $definitions, $state, $path, $ordered);
        }

        \array_pop($path);
        $state[$definition->id] = 2;
        $ordered[] = $definition;
    }
}
