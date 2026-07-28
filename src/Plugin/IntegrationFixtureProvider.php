<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Contributes orchestrator-owned infrastructure to an executing test run.
 */
interface IntegrationFixtureProvider extends Plugin
{
    /**
     * @return list<IntegrationFixtureDefinition>
     */
    public function integrationFixtures(): array;
}
