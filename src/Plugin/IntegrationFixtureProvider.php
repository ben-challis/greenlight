<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

/**
 * Supplies orchestrator-owned infrastructure for one test run.
 */
interface IntegrationFixtureProvider extends Plugin
{
    /**
     * @return list<IntegrationFixtureDefinition>
     */
    public function integrationFixtures(): array;
}
