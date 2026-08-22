<?php

declare(strict_types=1);

namespace Greenlight\IntegrationFixture;

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
     * @param iterable<IntegrationFixtureDefinition> $fixtureDefinitions
     * @param non-empty-string $runId
     * @param positive-int $configuredWorkers
     * @param positive-int $channelCount
     * @param array{int, int}|null $shard
     * @throws IntegrationFixtureError
     */
    public static function provision(
        iterable $fixtureDefinitions,
        string $runId,
        int $configuredWorkers,
        int $channelCount,
        ?array $shard,
    ): ProvisionedIntegrationFixtures {
        $definitions = [];

        foreach ($fixtureDefinitions as $definition) {
            if (isset($definitions[$definition->id])) {
                throw new IntegrationFixtureError(\sprintf(
                    'Integration fixture "%s" is declared more than once.',
                    $definition->id,
                ));
            }

            $definitions[$definition->id] = $definition;
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
     * @param array<string, IntegrationFixtureDefinition> $definitions
     *
     * @return list<IntegrationFixtureDefinition>
     * @throws IntegrationFixtureError
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
     * @throws IntegrationFixtureError
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
            $reversedCycle = [$definition->id];

            foreach (\array_reverse($path) as $ancestor) {
                $reversedCycle[] = $ancestor;

                if ($ancestor === $definition->id) {
                    break;
                }
            }

            $cycle = \array_reverse($reversedCycle);

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
