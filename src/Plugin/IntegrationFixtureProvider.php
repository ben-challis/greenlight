<?php

declare(strict_types=1);

namespace Greenlight\Plugin;

use Greenlight\IntegrationFixture\IntegrationFixtureDefinition;

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
