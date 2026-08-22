<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;
use Greenlight\IntegrationFixture\IntegrationFixtureError;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\PluginRegistry;

/**
 * Invokes integration fixture providers and validates their returned values.
 *
 * @internal
 */
final class IntegrationFixtureProviderAdapter
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return \Generator<int, IntegrationFixtureDefinition>
     * @throws IntegrationFixtureError
     */
    public static function definitions(PluginRegistry $plugins): \Generator
    {
        foreach ($plugins->integrationFixtureProviders() as $provider) {
            try {
                $provided = $provider->integrationFixtures();
            } catch (\Throwable $failure) {
                throw IntegrationFixtureError::provider($provider::class, $failure);
            }

            foreach (self::validatedDefinitions($provider, $provided) as $definition) {
                yield $definition;
            }
        }
    }

    /**
     * @param array<mixed> $provided
     *
     * @return list<IntegrationFixtureDefinition>
     * @throws IntegrationFixtureError
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
}
