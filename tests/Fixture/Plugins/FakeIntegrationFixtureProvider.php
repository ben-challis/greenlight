<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Plugins;

use Greenlight\Doubles\Fake;
use Greenlight\Plugin\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;

final class FakeIntegrationFixtureProvider implements Fake, IntegrationFixtureProvider
{
    /**
     * @param list<IntegrationFixtureDefinition> $definitions
     */
    public function __construct(private array $definitions) {}

    #[\Override]
    public function integrationFixtures(): array
    {
        return $this->definitions;
    }
}
